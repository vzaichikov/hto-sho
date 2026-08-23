<?php

namespace Tests\Unit;

use App\Data\SilpoCartContextData;
use App\Services\McpSilpoCartGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Enums\ProtocolVersion;
use Tests\TestCase;

class McpSilpoCartGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.silpo_mcp.url', 'https://silpo.test/mcp');
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $payload = $request->data();

            if (($payload['method'] ?? null) === 'notifications/initialized') {
                return Http::response('', 202);
            }

            if (($payload['method'] ?? null) === 'initialize') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => ProtocolVersion::LATEST->value,
                        'capabilities' => [],
                        'serverInfo' => ['name' => 'silpo-test', 'version' => '1.0.0'],
                    ],
                ]);
            }

            if (($payload['method'] ?? null) === 'tools/list') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => collect([
                        'silpo_get_categories_tree',
                        'silpo_get_product_sets',
                        'silpo_get_products',
                    ])->map(fn (string $name): array => [
                        'name' => $name,
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ])->all()],
                ]);
            }

            $toolName = data_get($payload, 'params.name');
            $structuredContent = match ($toolName) {
                'silpo_find_products_batch' => ['queries' => [[
                    'query' => data_get($payload, 'params.arguments.products.0'),
                    'products' => [['id' => 'product-1', 'name' => 'Вода']],
                ]]],
                'silpo_get_categories_tree' => ['tree' => [[
                    'slug' => 'frukty-ovochi-4788',
                    'children' => [[
                        'slug' => 'ovochi-4808',
                        'total' => 98,
                        'children' => [[
                            'slug' => 'kabachky-tsukini-4811',
                            'total' => 3,
                            'children' => [],
                        ]],
                    ]],
                ]]],
                'silpo_get_product_sets' => ['sets' => [[
                    'name' => 'Для легкої вечері',
                    'slug' => 'dlia-lehkoi-vecheri',
                ]]],
                'silpo_get_products' => ['products' => [[
                    'id' => 'zucchini-1',
                    'name' => 'Кабачок зелений',
                ]]],
                default => ['updated' => true],
            };

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [
                    'content' => [],
                    'isError' => false,
                    'structuredContent' => $structuredContent,
                ],
            ]);
        });
    }

    public function test_search_sends_exactly_one_product_query_and_commit_uses_one_absolute_batch(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);
        $cart = $this->cart();

        $products = $gateway->searchProducts('secret-token', $cart, 'вода питна', 8);
        $gateway->addOrUpdateProducts('secret-token', 'cart-1', [[
            'productId' => 'product-1',
            'companyId' => 'company-1',
            'branchId' => 'branch-1',
            'quantity' => 4,
            'addQuantity' => false,
        ]]);

        $this->assertSame('product-1', $products[0]['id']);
        $toolCalls = Http::recorded(
            fn (Request $request): bool => $request->data()['method'] === 'tools/call',
        )->values();
        $this->assertCount(2, $toolCalls);

        $searchArguments = data_get($toolCalls[0][0]->data(), 'params.arguments');
        $this->assertSame(['вода питна'], $searchArguments['products']);
        $this->assertSame('branch-1', $searchArguments['branchId']);
        $this->assertSame('DeliveryHome', $searchArguments['deliveryType']);
        $this->assertSame(8, $searchArguments['limit']);

        $commitArguments = data_get($toolCalls[1][0]->data(), 'params.arguments');
        $this->assertSame('cart-1', $commitArguments['shoppingCartId']);
        $this->assertCount(1, $commitArguments['products']);
        $this->assertFalse($commitArguments['products'][0]['addQuantity']);
        $this->assertSame(4, $commitArguments['products'][0]['quantity']);
    }

    public function test_catalog_discovery_flattens_categories_and_sets_then_browses_one_scope(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);
        $cart = $this->cart();

        $scopes = $gateway->getCatalogScopes('secret-token', $cart);
        $products = $gateway->browseProducts(
            'secret-token',
            $cart,
            'category',
            'kabachky-tsukini-4811',
            12,
        );

        $this->assertSame('kabachky-tsukini-4811', data_get($scopes, 'categories.2.slug'));
        $this->assertSame(2, data_get($scopes, 'categories.2.depth'));
        $this->assertSame('ovochi-4808', data_get($scopes, 'categories.2.parent_slug'));
        $this->assertSame('dlia-lehkoi-vecheri', data_get($scopes, 'sets.0.slug'));
        $this->assertSame('zucchini-1', data_get($products, '0.id'));

        $browseCall = Http::recorded(fn (Request $request): bool => (
            data_get($request->data(), 'params.name') === 'silpo_get_products'
        ))->sole()[0];
        $arguments = data_get($browseCall->data(), 'params.arguments');
        $this->assertSame('kabachky-tsukini-4811', $arguments['category']);
        $this->assertTrue($arguments['inStock']);
        $this->assertSame(12, $arguments['limit']);
        $this->assertArrayNotHasKey('set', $arguments);
    }

    public function test_expired_slot_refresh_copies_the_route_verifies_read_back_and_replay_is_idempotent(): void
    {
        $cartId = '11111111-1111-4111-8111-111111111111';
        $companyId = '22222222-2222-4222-8222-222222222222';
        $branchId = '33333333-3333-4333-8333-333333333333';
        $oldStart = '2026-08-22T10:00:00Z';
        $oldEnd = '2026-08-22T11:00:00Z';
        $newStart = '2026-08-25T10:00:00Z';
        $newEnd = '2026-08-25T11:00:00Z';
        $address = ['addressType' => 'delivery', 'latitude' => '50.4501', 'longitude' => '30.5234'];
        $shipments = [[
            'companyId' => $companyId,
            'branchId' => $branchId,
            'products' => [['productId' => '44444444-4444-4444-8444-444444444444', 'name' => 'Вода']],
        ]];
        $updated = false;
        $updateCalls = [];

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (
            $address,
            $cartId,
            $newEnd,
            $newStart,
            $oldEnd,
            $oldStart,
            $shipments,
            &$updateCalls,
            &$updated,
        ) {
            $payload = $request->data();
            $method = $payload['method'] ?? null;

            if ($method === 'notifications/initialized') {
                return Http::response('', 202);
            }

            if ($method === 'initialize') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'protocolVersion' => ProtocolVersion::LATEST->value,
                        'capabilities' => [],
                        'serverInfo' => ['name' => 'silpo-test', 'version' => '1.0.0'],
                    ],
                ]);
            }

            if ($method === 'tools/list') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => collect([
                        'silpo_get_my_shopping_cart',
                        'silpo_get_shopping_cart_by_id',
                        'silpo_get_time_slots',
                        'silpo_update_shopping_cart',
                    ])->map(fn (string $name): array => [
                        'name' => $name,
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ])->all()],
                ]);
            }

            $toolName = data_get($payload, 'params.name');
            $arguments = data_get($payload, 'params.arguments', []);

            if ($toolName === 'silpo_update_shopping_cart') {
                $updateCalls[] = $arguments;
                $updated = true;
                $structuredContent = ['updated' => true];
            } elseif ($toolName === 'silpo_get_my_shopping_cart') {
                $structuredContent = ['shoppingCartId' => $cartId];
            } elseif ($toolName === 'silpo_get_shopping_cart_by_id') {
                $structuredContent = ['cart' => [
                    'deliveryType' => 'DeliveryHome',
                    'timeslot' => [
                        'start' => $updated ? $newStart : $oldStart,
                        'end' => $updated ? $newEnd : $oldEnd,
                    ],
                    'address' => $address,
                    'shipments' => $shipments,
                    'calculation' => ['validations' => [], 'totalAfterDiscounts' => 50],
                ]];
            } elseif ($toolName === 'silpo_get_time_slots') {
                $structuredContent = ['slots' => [[
                    'deliveryType' => 'DeliveryHome',
                    'start' => $newStart,
                    'end' => $newEnd,
                    'available' => true,
                    'deliveryCost' => 69,
                ]]];
            } else {
                $structuredContent = [];
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [
                    'content' => [],
                    'isError' => false,
                    'structuredContent' => $structuredContent,
                ],
            ]);
        });

        $gateway = $this->app->make(McpSilpoCartGateway::class);
        $candidate = $gateway->getCartRefreshCandidate('secret-token');
        $this->assertNotNull($candidate);
        $this->assertSame($newStart, $candidate->candidateSlotStart);

        $refreshed = $gateway->refreshCartTimeslot(
            'secret-token',
            $candidate->routeFingerprint,
            $candidate->currentSlotFingerprint,
            $candidate->candidateSlotStart,
            $candidate->candidateSlotEnd,
        );
        $this->assertNotNull($refreshed);
        $this->assertSame($newStart, $refreshed->slotStart);
        $this->assertCount(1, $updateCalls);
        $this->assertSame([
            'shoppingCartId' => $cartId,
            'deliveryType' => 'DeliveryHome',
            'timeslot' => ['start' => $newStart, 'end' => $newEnd],
            'address' => $address,
            'shipments' => $shipments,
        ], $updateCalls[0]);

        $replayed = $gateway->refreshCartTimeslot(
            'secret-token',
            $candidate->routeFingerprint,
            $candidate->currentSlotFingerprint,
            $candidate->candidateSlotStart,
            $candidate->candidateSlotEnd,
        );
        $this->assertNotNull($replayed);
        $this->assertSame($newStart, $replayed->slotStart);
        $this->assertCount(1, $updateCalls);

        $slotCalls = Http::recorded(fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_get_time_slots');
        $this->assertNotEmpty($slotCalls);

        foreach ($slotCalls as [$request]) {
            $this->assertMatchesRegularExpression(
                '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:00\+00:00\z/',
                (string) data_get($request->data(), 'params.arguments.start'),
            );
        }
    }

    private function cart(): SilpoCartContextData
    {
        return new SilpoCartContextData(
            cartId: 'cart-1',
            deliveryType: 'DeliveryHome',
            branchId: 'branch-1',
            companyId: 'company-1',
            slotStart: '2026-08-23T10:00:00+03:00',
            slotEnd: '2026-08-23T11:00:00+03:00',
            items: [],
            validations: [],
            slot: [],
            totalAfterDiscounts: 0,
        );
    }
}
