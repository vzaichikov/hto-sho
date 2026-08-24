<?php

namespace App\Services;

use App\Contracts\SilpoCartGateway;
use App\Data\SilpoCartContextData;
use App\Data\SilpoCartRefreshCandidateData;
use App\Data\SilpoFulfilmentSnapshotData;
use App\HarnessEntryKind;
use App\Models\HarnessRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use JsonException;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool;
use Laravel\Mcp\Client\Schema\ToolResult;
use Laravel\Mcp\Exceptions\ClientException;
use RuntimeException;
use Throwable;

final class McpSilpoCartGateway implements SilpoCartGateway
{
    /** @var Collection<string, Tool>|null */
    private ?Collection $toolManifest = null;

    private ?string $manifestTokenFingerprint = null;

    private ?string $activeAccessToken = null;

    /** @var array<int, string> */
    private const REQUIRED_TOOLS = [
        'silpo_get_my_shopping_cart',
        'silpo_get_shopping_cart_by_id',
        'silpo_get_time_slots',
        'silpo_find_products_batch',
        'silpo_get_product_details',
        'silpo_add_or_update_cart_products',
    ];

    /** @var array<int, string> */
    private const REFRESH_TOOLS = [
        'silpo_get_my_shopping_cart',
        'silpo_get_shopping_cart_by_id',
        'silpo_get_time_slots',
        'silpo_update_shopping_cart',
    ];

    /** @var array<int, string> */
    private const CATALOG_DISCOVERY_TOOLS = [
        'silpo_get_categories_tree',
        'silpo_get_product_sets',
        'silpo_get_products',
    ];

    /** @var array<int, string> */
    private const FULFILMENT_TOOLS = [
        'silpo_get_my_shopping_cart',
        'silpo_get_shopping_cart_by_id',
        'silpo_get_my_delivery_addresses',
        'silpo_find_address',
        'silpo_get_available_delivery_types',
        'silpo_get_time_slots',
        'silpo_list_branches',
        'silpo_find_nova_poshta_settlements',
        'silpo_find_nova_poshta_offices',
        'silpo_update_shopping_cart',
    ];

    /** @var array<int, string> */
    private const FULFILMENT_DELIVERY_TYPES = [
        'DeliveryHome',
        'WideAssortDelivery',
        'SelfPickup',
        'NovaPoshta',
    ];

    public function __construct(private readonly HarnessRecorder $harnessRecorder) {}

    public function getFulfilmentSnapshot(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun, self::FULFILMENT_TOOLS);

            return $this->readFulfilmentSnapshot($client, $harnessRun);
        } finally {
            $client->disconnect();
        }
    }

    public function getSavedDeliveryAddresses(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->readListTool(
            $accessToken,
            'silpo_get_my_delivery_addresses',
            [],
            'addresses',
            'читання збережених адрес',
            $harnessRun,
        );
    }

    public function findDeliveryAddresses(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->readListTool(
            $accessToken,
            'silpo_find_address',
            ['address' => $query],
            'addresses',
            'пошук адреси',
            $harnessRun,
        );
    }

    public function getAvailableDeliveryTypes(
        string $accessToken,
        float $latitude,
        float $longitude,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            $tools = $this->assertRequiredTools($client, $harnessRun, [
                'silpo_get_available_delivery_types',
                'silpo_get_time_slots',
            ]);
            $supportedTypes = data_get(
                $tools->get('silpo_get_time_slots')?->inputSchema,
                'properties.deliveryTypes.items.enum',
                [],
            );

            if (! is_array($supportedTypes) || $supportedTypes === []) {
                throw new RuntimeException('Сільпо переставило дороговкази. Гусь зупинився, щоб нічого не вигадувати.');
            }

            $supportedTypes = collect($supportedTypes)
                ->intersect(self::FULFILMENT_DELIVERY_TYPES)
                ->values()
                ->all();

            $payload = $this->payload($this->callTool($client, 'silpo_get_available_delivery_types', [
                'latitude' => $latitude,
                'longitude' => $longitude,
            ], $harnessRun), 'читання способів отримання');

            return collect(data_get($payload, 'options', []))
                ->filter(fn (mixed $option): bool => is_array($option)
                    && in_array(data_get($option, 'deliveryType'), $supportedTypes, true))
                ->values()
                ->all();
        } finally {
            $client->disconnect();
        }
    }

    public function getFulfilmentBranches(
        string $accessToken,
        bool $pickup,
        bool $novaPoshta,
        ?HarnessRun $harnessRun = null,
    ): array {
        $arguments = ['limit' => 1000];

        if ($pickup) {
            $arguments['hasPickup'] = true;
        }

        if ($novaPoshta) {
            $arguments['hasNP'] = true;
        }

        return $this->readListTool(
            $accessToken,
            'silpo_list_branches',
            $arguments,
            'branches',
            'читання магазинів',
            $harnessRun,
        );
    }

    public function findNovaPoshtaSettlements(
        string $accessToken,
        string $query,
        ?HarnessRun $harnessRun = null,
    ): array {
        return $this->readListTool(
            $accessToken,
            'silpo_find_nova_poshta_settlements',
            ['title' => $query],
            'settlements',
            'пошук міста Нової пошти',
            $harnessRun,
        );
    }

    public function findNovaPoshtaOffices(
        string $accessToken,
        string $settlementId,
        ?string $query = null,
        ?HarnessRun $harnessRun = null,
    ): array {
        $arguments = ['settlementId' => $settlementId];

        if ($query !== null && $query !== '') {
            $arguments['title'] = $query;
        }

        return $this->readListTool(
            $accessToken,
            'silpo_find_nova_poshta_offices',
            $arguments,
            'offices',
            'читання відділень Нової пошти',
            $harnessRun,
        );
    }

    public function getFulfilmentSlots(
        string $accessToken,
        string $branchId,
        string $deliveryType,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            $tools = $this->assertRequiredTools($client, $harnessRun, ['silpo_get_time_slots']);
            $supportedTypes = data_get(
                $tools->get('silpo_get_time_slots')?->inputSchema,
                'properties.deliveryTypes.items.enum',
                [],
            );

            if (! is_array($supportedTypes)
                || ! in_array($deliveryType, self::FULFILMENT_DELIVERY_TYPES, true)
                || ! in_array($deliveryType, $supportedTypes, true)) {
                throw new RuntimeException('Цей спосіб отримання Сільпо ще не дає Гусю перевірити час.');
            }

            return $this->availableSlots($client, [
                'branch_id' => $branchId,
                'delivery_type' => $deliveryType,
            ], $harnessRun);
        } finally {
            $client->disconnect();
        }
    }

    public function updateFulfilment(
        string $accessToken,
        string $cartId,
        string $deliveryType,
        string $slotStart,
        string $slotEnd,
        array $address,
        array $shipments,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoFulfilmentSnapshotData {
        $client = $this->client($accessToken);

        try {
            $tools = $this->assertRequiredTools($client, $harnessRun, [
                'silpo_get_shopping_cart_by_id',
                'silpo_update_shopping_cart',
            ]);
            $required = data_get($tools->get('silpo_update_shopping_cart')?->inputSchema, 'required', []);
            $expected = ['shoppingCartId', 'deliveryType', 'timeslot', 'address', 'shipments'];

            if (! is_array($required)
                || collect($expected)->diff($required)->isNotEmpty()
                || collect($required)->diff($expected)->isNotEmpty()) {
                throw new RuntimeException('Сільпо змінило правила маршруту. Гусь зупинився, щоб нічого не вигадувати.');
            }

            $this->payload($this->callTool($client, 'silpo_update_shopping_cart', [
                'shoppingCartId' => $cartId,
                'deliveryType' => $deliveryType,
                'timeslot' => ['start' => $slotStart, 'end' => $slotEnd],
                'address' => $address,
                'shipments' => $shipments,
            ], $harnessRun), 'оновлення маршруту отримання');

            return $this->readFulfilmentSnapshotById($client, $cartId, $harnessRun);
        } finally {
            $client->disconnect();
        }
    }

    public function getReadyCart(string $accessToken, ?HarnessRun $harnessRun = null): ?SilpoCartContextData
    {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun);
            $cartState = $this->readCartState($client, $harnessRun);

            if ($cartState === null) {
                return null;
            }

            $validatedSlot = collect($this->availableSlots($client, $cartState, $harnessRun))
                ->first(fn (mixed $slot): bool => is_array($slot)
                    && data_get($slot, 'start') === $cartState['slot_start']
                    && data_get($slot, 'end') === $cartState['slot_end']);

            if (! is_array($validatedSlot)) {
                return null;
            }

            return SilpoCartContextData::fromMcp(
                $cartState['cart_id'],
                $cartState['cart_payload'],
                $validatedSlot,
            );
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() === 'Кошик Сільпо ще не має повного маршруту доставки.') {
                return null;
            }

            throw $exception;
        } finally {
            $client->disconnect();
        }
    }

    public function getCartRefreshCandidate(
        string $accessToken,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartRefreshCandidateData {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun, self::REFRESH_TOOLS);
            $cartState = $this->readCartState($client, $harnessRun);

            if ($cartState === null) {
                return null;
            }

            $availableSlots = $this->availableSlots($client, $cartState, $harnessRun);
            $currentSlotIsAvailable = collect($availableSlots)->contains(
                fn (array $slot): bool => data_get($slot, 'start') === $cartState['slot_start']
                    && data_get($slot, 'end') === $cartState['slot_end'],
            );

            if ($currentSlotIsAvailable) {
                return null;
            }

            $candidate = collect($availableSlots)->first();
            $candidateStart = data_get($candidate, 'start');
            $candidateEnd = data_get($candidate, 'end');

            if (! is_string($candidateStart) || $candidateStart === ''
                || ! is_string($candidateEnd) || $candidateEnd === '') {
                return null;
            }

            return new SilpoCartRefreshCandidateData(
                deliveryType: $cartState['delivery_type'],
                currentSlotStart: $cartState['slot_start'],
                currentSlotEnd: $cartState['slot_end'],
                candidateSlotStart: $candidateStart,
                candidateSlotEnd: $candidateEnd,
                routeFingerprint: $cartState['route_fingerprint'],
                currentSlotFingerprint: $cartState['current_slot_fingerprint'],
            );
        } finally {
            $client->disconnect();
        }
    }

    public function refreshCartTimeslot(
        string $accessToken,
        string $routeFingerprint,
        string $currentSlotFingerprint,
        string $slotStart,
        string $slotEnd,
        ?HarnessRun $harnessRun = null,
    ): ?SilpoCartContextData {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun, self::REFRESH_TOOLS);
            $cartState = $this->readCartState($client, $harnessRun);

            if ($cartState === null || ! hash_equals($cartState['route_fingerprint'], $routeFingerprint)) {
                return null;
            }

            $validatedSlot = collect($this->availableSlots($client, $cartState, $harnessRun))
                ->first(fn (array $slot): bool => data_get($slot, 'start') === $slotStart
                    && data_get($slot, 'end') === $slotEnd);

            if (! is_array($validatedSlot)) {
                return null;
            }

            if ($cartState['slot_start'] === $slotStart && $cartState['slot_end'] === $slotEnd) {
                return SilpoCartContextData::fromMcp(
                    $cartState['cart_id'],
                    $cartState['cart_payload'],
                    $validatedSlot,
                );
            }

            if (! hash_equals($cartState['current_slot_fingerprint'], $currentSlotFingerprint)) {
                return null;
            }

            $this->payload($this->callTool($client, 'silpo_update_shopping_cart', [
                'shoppingCartId' => $cartState['cart_id'],
                'deliveryType' => $cartState['delivery_type'],
                'timeslot' => ['start' => $slotStart, 'end' => $slotEnd],
                'address' => $cartState['address'],
                'shipments' => $cartState['shipments'],
            ], $harnessRun), 'оновлення часу отримання');

            $verifiedState = $this->readCartStateById(
                $client,
                $cartState['cart_id'],
                $harnessRun,
            );

            if ($verifiedState === null
                || ! hash_equals($verifiedState['route_fingerprint'], $routeFingerprint)
                || $verifiedState['slot_start'] !== $slotStart
                || $verifiedState['slot_end'] !== $slotEnd) {
                return null;
            }

            $verifiedSlot = collect($this->availableSlots($client, $verifiedState, $harnessRun))
                ->first(fn (array $slot): bool => data_get($slot, 'start') === $slotStart
                    && data_get($slot, 'end') === $slotEnd);

            if (! is_array($verifiedSlot)) {
                return null;
            }

            return SilpoCartContextData::fromMcp(
                $verifiedState['cart_id'],
                $verifiedState['cart_payload'],
                $verifiedSlot,
            );
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

    public function getCatalogScopes(
        string $accessToken,
        SilpoCartContextData $cart,
        ?HarnessRun $harnessRun = null,
    ): array {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun, self::CATALOG_DISCOVERY_TOOLS);
            $categoryPayload = $this->payload($this->callTool($client, 'silpo_get_categories_tree', [
                'branchId' => $cart->branchId,
                'deliveryType' => $cart->deliveryType,
                'timeslotStart' => $cart->slotStart,
                'timeslotEnd' => $cart->slotEnd,
            ], $harnessRun), 'читання категорій товарів');
            $setsPayload = $this->payload($this->callTool($client, 'silpo_get_product_sets', [
                'branchId' => $cart->branchId,
                'deliveryType' => $cart->deliveryType,
            ], $harnessRun), 'читання тематичних добірок');

            return [
                'categories' => $this->flattenCategories(data_get($categoryPayload, 'tree', [])),
                'sets' => collect(data_get($setsPayload, 'sets', []))
                    ->filter(fn (mixed $set): bool => is_array($set) && filled(data_get($set, 'slug')))
                    ->map(fn (array $set): array => [
                        'type' => 'set',
                        'slug' => (string) data_get($set, 'slug'),
                        'label' => (string) data_get($set, 'name', data_get($set, 'title', data_get($set, 'slug'))),
                    ])
                    ->values()
                    ->all(),
            ];
        } finally {
            $client->disconnect();
        }
    }

    public function browseProducts(
        string $accessToken,
        SilpoCartContextData $cart,
        string $scopeType,
        string $scopeSlug,
        int $limit = 12,
        ?HarnessRun $harnessRun = null,
    ): array {
        if (! in_array($scopeType, ['category', 'set'], true) || blank($scopeSlug)) {
            throw new RuntimeException('Невідома область каталогу Сільпо.');
        }

        $client = $this->client($accessToken);

        try {
            $payload = $this->payload($this->callTool($client, 'silpo_get_products', [
                'branchId' => $cart->branchId,
                'deliveryType' => $cart->deliveryType,
                'timeslotStart' => $cart->slotStart,
                'timeslotEnd' => $cart->slotEnd,
                'inStock' => true,
                $scopeType => $scopeSlug,
                'limit' => min(max($limit, 1), 20),
                'sortBy' => 'popularity',
                'sortDirection' => 'desc',
            ], $harnessRun), 'перегляд області каталогу');

            return collect(data_get($payload, 'products', []))
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
        $tokenFingerprint = hash('sha256', $accessToken);

        if ($this->manifestTokenFingerprint !== null
            && ! hash_equals($this->manifestTokenFingerprint, $tokenFingerprint)) {
            $this->toolManifest = null;
        }

        $this->manifestTokenFingerprint = $tokenFingerprint;
        $this->activeAccessToken = $accessToken;

        return $this->freshClient($accessToken);
    }

    private function freshClient(string $accessToken): Client
    {
        return Client::web((string) config('services.silpo_mcp.url'))
            ->withToken($accessToken)
            ->withTimeout((float) config('services.silpo_mcp.timeout', 20));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array<string, mixed>>
     */
    private function readListTool(
        string $accessToken,
        string $tool,
        array $arguments,
        string $key,
        string $operation,
        ?HarnessRun $harnessRun,
    ): array {
        $client = $this->client($accessToken);

        try {
            $this->assertRequiredTools($client, $harnessRun, [$tool]);
            $payload = $this->payload(
                $this->callTool($client, $tool, $arguments, $harnessRun),
                $operation,
            );

            return collect(data_get($payload, $key, []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->values()
                ->all();
        } finally {
            $client->disconnect();
        }
    }

    /**
     * @param  array<int, string>  $requiredTools
     */
    private function assertRequiredTools(
        Client $client,
        ?HarnessRun $harnessRun,
        array $requiredTools = self::REQUIRED_TOOLS,
    ): Collection {
        if ($this->toolManifest === null) {
            try {
                $this->toolManifest = $this->discoverTools($client, $harnessRun, 1);
            } catch (Throwable $throwable) {
                if (! $this->isTransientManifestFailure($throwable) || $this->activeAccessToken === null) {
                    throw $throwable;
                }

                $client->disconnect();
                $retryClient = $this->freshClient($this->activeAccessToken);

                try {
                    try {
                        $this->toolManifest = $this->discoverTools($retryClient, $harnessRun, 2);
                    } catch (Throwable $retryFailure) {
                        if ($this->isTransientManifestFailure($retryFailure)) {
                            throw new RuntimeException(
                                'Сільпо двічі не відкрило Гусю список маршрутів. Спробуйте ще раз за хвилину.',
                                previous: $retryFailure,
                            );
                        }

                        throw $retryFailure;
                    }
                } finally {
                    $retryClient->disconnect();
                }
            }
        }

        $tools = $this->toolManifest;

        $missingTools = collect($requiredTools)->diff($tools->keys());

        if ($missingTools->isNotEmpty()) {
            throw new RuntimeException('Сільпо сховало потрібні Гусю двері. Він зупинився, щоб нічого не вигадувати.');
        }

        return $tools;
    }

    /** @return Collection<string, Tool> */
    private function discoverTools(Client $client, ?HarnessRun $harnessRun, int $attempt): Collection
    {
        if ($harnessRun === null) {
            return $client->tools();
        }

        $entry = $this->harnessRecorder->startExternal(
            run: $harnessRun,
            kind: HarnessEntryKind::Mcp,
            title: $attempt === 1
                ? 'MCP: список доступних інструментів'
                : 'MCP: повторне читання доступних інструментів',
            method: 'POST',
            endpoint: (string) config('services.silpo_mcp.url'),
            requestPayload: ['jsonrpc' => '2.0', 'method' => 'tools/list', 'attempt' => $attempt],
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

            return $tools;
        } catch (Throwable $throwable) {
            $this->harnessRecorder->failExternal(
                $entry,
                $throwable,
                (int) round((hrtime(true) - $startedAt) / 1_000_000),
            );

            throw $throwable;
        }
    }

    private function isTransientManifestFailure(Throwable $throwable): bool
    {
        return $throwable instanceof ClientException
            && preg_match('/Unexpected HTTP status \[(?:502|503|504)\]/', $throwable->getMessage()) === 1;
    }

    private function readFulfilmentSnapshot(
        Client $client,
        ?HarnessRun $harnessRun,
    ): ?SilpoFulfilmentSnapshotData {
        $cartIdResult = $this->callTool($client, 'silpo_get_my_shopping_cart', [], $harnessRun);

        if ($cartIdResult->isError && str_contains(mb_strtolower($cartIdResult->text()), 'resource not found')) {
            return null;
        }

        $cartIdPayload = $this->payload($cartIdResult, 'читання поточного кошика');
        $cartId = data_get($cartIdPayload, 'shoppingCartId');

        if (! is_string($cartId) || $cartId === '') {
            return null;
        }

        return $this->readFulfilmentSnapshotById($client, $cartId, $harnessRun);
    }

    private function readFulfilmentSnapshotById(
        Client $client,
        string $cartId,
        ?HarnessRun $harnessRun,
    ): SilpoFulfilmentSnapshotData {
        $cartPayload = $this->payload(
            $this->callTool($client, 'silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId], $harnessRun),
            'читання складу кошика',
        );

        return SilpoFulfilmentSnapshotData::fromMcp($cartId, $cartPayload);
    }

    /**
     * @return array{
     *     cart_id: string,
     *     cart_payload: array<string, mixed>,
     *     delivery_type: string,
     *     branch_id: string,
     *     company_id: string,
     *     slot_start: string,
     *     slot_end: string,
     *     address: array<string, mixed>,
     *     shipments: array<int, array<string, mixed>>,
     *     route_fingerprint: string,
     *     current_slot_fingerprint: string
     * }|null
     */
    private function readCartState(Client $client, ?HarnessRun $harnessRun): ?array
    {
        $cartIdResult = $this->callTool($client, 'silpo_get_my_shopping_cart', [], $harnessRun);

        if ($cartIdResult->isError && str_contains(mb_strtolower($cartIdResult->text()), 'resource not found')) {
            return null;
        }

        $cartIdPayload = $this->payload($cartIdResult, 'читання поточного кошика');
        $cartId = data_get($cartIdPayload, 'shoppingCartId');

        if (! is_string($cartId) || $cartId === '') {
            return null;
        }

        return $this->readCartStateById($client, $cartId, $harnessRun);
    }

    /**
     * @return array{
     *     cart_id: string,
     *     cart_payload: array<string, mixed>,
     *     delivery_type: string,
     *     branch_id: string,
     *     company_id: string,
     *     slot_start: string,
     *     slot_end: string,
     *     address: array<string, mixed>,
     *     shipments: array<int, array<string, mixed>>,
     *     route_fingerprint: string,
     *     current_slot_fingerprint: string
     * }|null
     */
    private function readCartStateById(
        Client $client,
        string $cartId,
        ?HarnessRun $harnessRun,
    ): ?array {
        $cartPayload = $this->payload(
            $this->callTool($client, 'silpo_get_shopping_cart_by_id', ['shoppingCartId' => $cartId], $harnessRun),
            'читання складу кошика',
        );
        $deliveryType = data_get($cartPayload, 'cart.deliveryType');
        $branchId = data_get($cartPayload, 'cart.shipments.0.branchId');
        $companyId = data_get($cartPayload, 'cart.shipments.0.companyId');
        $slotStart = data_get($cartPayload, 'cart.timeslot.start');
        $slotEnd = data_get($cartPayload, 'cart.timeslot.end');
        $address = data_get($cartPayload, 'cart.address');
        $shipments = data_get($cartPayload, 'cart.shipments');

        if (! is_string($deliveryType) || $deliveryType === ''
            || ! is_string($branchId) || $branchId === ''
            || ! is_string($companyId) || $companyId === ''
            || ! is_string($slotStart) || $slotStart === ''
            || ! is_string($slotEnd) || $slotEnd === ''
            || ! is_array($address) || $address === []
            || ! is_array($shipments) || $shipments === []) {
            return null;
        }

        $routeFingerprint = $this->fingerprint([
            'cart_id' => $cartId,
            'delivery_type' => $deliveryType,
            'address' => $address,
            'shipments' => collect($shipments)
                ->filter(fn (mixed $shipment): bool => is_array($shipment))
                ->map(fn (array $shipment): array => Arr::only($shipment, ['companyId', 'branchId']))
                ->values()
                ->all(),
        ]);

        return [
            'cart_id' => $cartId,
            'cart_payload' => $cartPayload,
            'delivery_type' => $deliveryType,
            'branch_id' => $branchId,
            'company_id' => $companyId,
            'slot_start' => $slotStart,
            'slot_end' => $slotEnd,
            'address' => $address,
            'shipments' => $shipments,
            'route_fingerprint' => $routeFingerprint,
            'current_slot_fingerprint' => $this->fingerprint([
                'route_fingerprint' => $routeFingerprint,
                'slot_start' => $slotStart,
                'slot_end' => $slotEnd,
            ]),
        ];
    }

    /**
     * @param  array{branch_id: string, delivery_type: string}  $cartState
     * @return array<int, array<string, mixed>>
     */
    private function availableSlots(Client $client, array $cartState, ?HarnessRun $harnessRun): array
    {
        $slotsPayload = $this->payload($this->callTool($client, 'silpo_get_time_slots', [
            'branchId' => $cartState['branch_id'],
            'deliveryTypes' => [$cartState['delivery_type']],
            'start' => CarbonImmutable::now('UTC')->startOfMinute()->format('Y-m-d\TH:i:sP'),
            'limit' => 100,
        ], $harnessRun), 'перевірка часу доставки');

        return collect(data_get($slotsPayload, 'slots', []))
            ->filter(fn (mixed $slot): bool => is_array($slot)
                && data_get($slot, 'available') === true
                && data_get($slot, 'deliveryType') === $cartState['delivery_type']
                && is_string(data_get($slot, 'start'))
                && is_string(data_get($slot, 'end')))
            ->sortBy(fn (array $slot): string => (string) data_get($slot, 'start'))
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $value */
    private function fingerprint(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value);

        return collect($value)
            ->map(fn (mixed $item): mixed => $this->canonicalize($item))
            ->all();
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

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function flattenCategories(array $nodes, int $depth = 0, ?string $parentSlug = null): array
    {
        return collect($nodes)
            ->filter(fn (mixed $node): bool => is_array($node))
            ->flatMap(function (array $node) use ($depth, $parentSlug): array {
                $slug = data_get($node, 'slug');
                $current = is_string($slug) && $slug !== ''
                    ? [[
                        'type' => 'category',
                        'slug' => $slug,
                        'label' => (string) data_get($node, 'name', data_get($node, 'title', $slug)),
                        'depth' => $depth,
                        'parent_slug' => $parentSlug,
                        'total' => data_get($node, 'total'),
                    ]]
                    : [];

                return [
                    ...$current,
                    ...$this->flattenCategories(
                        data_get($node, 'children', []),
                        $depth + 1,
                        is_string($slug) && $slug !== '' ? $slug : $parentSlug,
                    ),
                ];
            })
            ->values()
            ->all();
    }
}
