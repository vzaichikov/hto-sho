<?php

namespace App\Services;

use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\HarnessEntryKind;
use App\Models\HarnessRun;
use JsonException;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Schema\ToolResult;
use RuntimeException;
use Throwable;

final class McpSilpoCartGateway implements SilpoCartGateway
{
    /** @var array<int, string> */
    private const REQUIRED_TOOLS = [
        'silpo_get_my_shopping_cart',
        'silpo_get_shopping_cart_by_id',
        'silpo_get_time_slots',
        'silpo_find_products_batch',
        'silpo_get_product_details',
        'silpo_add_or_update_cart_products',
    ];

    public function __construct(private readonly HarnessRecorder $harnessRecorder) {}

    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData
    {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun);
            $cartIdResult = $this->callTool($client, 'silpo_get_my_shopping_cart', [], $harnessRun);

            if ($cartIdResult->isError && str_contains(mb_strtolower($cartIdResult->text()), 'resource not found')) {
                return null;
            }

            $cartIdPayload = $this->payload($cartIdResult, 'читання поточного кошика');
            $cartId = data_get($cartIdPayload, 'shoppingCartId');

            if (! is_string($cartId) || $cartId === '') {
                return null;
            }

            $cartPayload = $this->payload(
                $this->callTool($client, 'silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId], $harnessRun),
                'читання складу кошика',
            );
            $branchId = data_get($cartPayload, 'cart.shipments.0.branchId');
            $deliveryType = data_get($cartPayload, 'cart.deliveryType');
            $slotStart = data_get($cartPayload, 'cart.timeslot.start');
            $slotEnd = data_get($cartPayload, 'cart.timeslot.end');

            if (! is_string($branchId) || $branchId === ''
                || ! is_string($deliveryType) || $deliveryType === ''
                || ! is_string($slotStart) || $slotStart === ''
                || ! is_string($slotEnd) || $slotEnd === '') {
                return null;
            }

            $slotsPayload = $this->payload($this->callTool($client, 'silpo_get_time_slots', [
                'branchId' => $branchId,
                'deliveryTypes' => [$deliveryType],
                'start' => $slotStart,
                'end' => $slotEnd,
                'limit' => 100,
            ], $harnessRun), 'перевірка часу доставки');
            $validatedSlot = collect(data_get($slotsPayload, 'slots', []))
                ->first(fn (mixed $slot): bool => is_array($slot)
                    && data_get($slot, 'available') === true
                    && data_get($slot, 'deliveryType') === $deliveryType
                    && data_get($slot, 'start') === $slotStart
                    && data_get($slot, 'end') === $slotEnd);

            if (! is_array($validatedSlot)) {
                return null;
            }

            return SilpoCartContextData::fromMcp($cartId, $cartPayload, $validatedSlot);
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Кошик Сільпо ще не має повного маршруту доставки.') {
                return null;
            }

            throw $exception;
        } finally {
            $client->disconnect();
        }
    }

    public function searchProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $query,
        int $limit = 8,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            $payload = $this->payload($this->callTool($client, 'silpo_find_products_batch', [
                'branchId' => $cart->branchId,
                'deliveryType' => $cart->deliveryType,
                'timeslotStart' => $cart->slotStart,
                'timeslotEnd' => $cart->slotEnd,
                'products' => [$query],
                'limit' => min(max($limit, 1), 20),
            ], $harnessRun), 'пошук товару');

            return collect(data_get($payload, 'queries.0.products', []))
                ->filter(fn (mixed $product): bool => is_array($product))
                ->values()
                ->all();
        } finally {
            $client->disconnect();
        }
    }

    public function getProductDetails(
        string $accessToken,
        SilpoCartContextData $cart,
        string $slug,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            $payload = $this->payload($this->callTool($client, 'silpo_get_product_details', [
                'branchId' => $cart->branchId,
                'slug' => $slug,
                'deliveryType' => $cart->deliveryType,
                'timeslotStart' => $cart->slotStart,
                'timeslotEnd' => $cart->slotEnd,
            ], $harnessRun), 'перевірка товару');

            $product = data_get($payload, 'product');

            if (! is_array($product)) {
                throw new RuntimeException('Сільпо не повернуло деталі товару.');
            }

            return $product;
        } finally {
            $client->disconnect();
        }
    }

    public function addOrUpdateProducts(
        string $accessToken,
        string $cartId,
        array $products,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            return $this->payload($this->callTool($client, 'silpo_add_or_update_cart_products', [
                'shoppingCartId' => $cartId,
                'products' => $products,
            ], $harnessRun), 'запис товарів у кошик');
        } finally {
            $client->disconnect();
        }
    }

    private function client(string $accessToken): Client
    {
        return Client::web((string) config('services.silpo_mcp.url'))
            ->withToken($accessToken)
            ->withTimeout((float) config('services.silpo_mcp.timeout', 20));
    }

    private function assertRequiredTools(Client $client, ?HarnessRun $harnessRun): void
    {
        if ($harnessRun === null) {
            $tools = $client->tools();
        } else {
            $entry = $this->harnessRecorder->startExternal(
                run: $harnessRun,
                kind: HarnessEntryKind::Mcp,
                title: 'MCP: список доступних інструментів',
                method: 'POST',
                endpoint: (string) config('services.silpo_mcp.url'),
                requestPayload: ['jsonrpc' => '2.0', 'method' => 'tools/list'],
            );
            $startedAt = hrtime(true);

            try {
                $tools = $client->tools();
                $this->harnessRecorder->completeExternal(
                    entry: $entry,
                    responsePayload: ['tools' => $tools->keys()->values()->all()],
                    statusCode: 200,
                    durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
                );
            } catch (Throwable $throwable) {
                $this->harnessRecorder->failExternal(
                    $entry,
                    $throwable,
                    (int) round((hrtime(true) - $startedAt) / 1_000_000),
                );

                throw $throwable;
            }
        }

        $missingTools = collect(self::REQUIRED_TOOLS)->diff($tools->keys());

        if ($missingTools->isNotEmpty()) {
            throw new RuntimeException('Silpo MCP змінило обовʼязкові інструменти кошика.');
        }
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(
        Client $client,
        string $name,
        array $arguments,
        ?HarnessRun $harnessRun,
    ): ToolResult {
        if ($harnessRun === null) {
            return $client->callTool($name, $arguments);
        }

        $entry = $this->harnessRecorder->startExternal(
            run: $harnessRun,
            kind: HarnessEntryKind::Mcp,
            title: 'MCP: '.$name,
            method: 'POST',
            endpoint: (string) config('services.silpo_mcp.url'),
            requestPayload: [
                'jsonrpc' => '2.0',
                'method' => 'tools/call',
                'params' => ['name' => $name, 'arguments' => $arguments],
            ],
        );
        $startedAt = hrtime(true);

        try {
            $result = $client->callTool($name, $arguments);
            $this->harnessRecorder->completeExternal(
                entry: $entry,
                responsePayload: [
                    'is_error' => $result->isError,
                    'structured_content' => $result->structuredContent,
                    'text' => $result->text(),
                ],
                statusCode: 200,
                durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
            );

            return $result;
        } catch (Throwable $throwable) {
            $this->harnessRecorder->failExternal(
                $entry,
                $throwable,
                (int) round((hrtime(true) - $startedAt) / 1_000_000),
            );

            throw $throwable;
        }
    }

    /** @return array<string, mixed> */
    private function payload(ToolResult $result, string $operation): array
    {
        if ($result->isError) {
            throw new RuntimeException("Сільпо не завершило операцію: {$operation}.");
        }

        if ($result->structuredContent !== null) {
            return $result->structuredContent;
        }

        try {
            $payload = json_decode($result->text(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Сільпо повернуло неочікувану відповідь під час: {$operation}.", previous: $exception);
        }

        if (! is_array($payload)) {
            throw new RuntimeException("Сільпо повернуло неочікувану відповідь під час: {$operation}.");
        }

        return $payload;
    }
}
