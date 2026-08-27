<?php

namespace Tests\Unit;

use App\Data\SilpoCartContextData;
use App\Services\McpSilpoCartGateway;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Enums\ProtocolVersion;
use RuntimeException;
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
                        'silpo_find_address',
                        'silpo_find_nova_poshta_offices',
                        'silpo_get_available_delivery_types',
                        'silpo_get_time_slots',
                        'silpo_list_branches',
                    ])->map(fn (string $name): array => [
                        'name' => $name,
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => $name === 'silpo_get_time_slots'
                                ? ['deliveryTypes' => ['items' => ['enum' => [
                                    'DeliveryHome',
                                    'WideAssortDelivery',
                                    'SelfPickup',
                                    'NovaPoshta',
                                ]]]]
                                : (object) [],
                        ],
                    ])->all()],
                ]);
            }

            $toolName = data_get($payload, 'params.name');
            $toolError = $toolName === 'silpo_find_address'
                && data_get($payload, 'params.arguments.address') === 'error-with-details';
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
                'silpo_find_address' => ['addresses' => [[
                    'address' => data_get($payload, 'params.arguments.address'),
                    'latitude' => 50.45,
                    'longitude' => 30.52,
                ]]],
                'silpo_find_nova_poshta_offices' => ['offices' => [[
                    'id' => 'office-1',
                    'title' => 'Відділення №1',
                ]]],
                'silpo_get_available_delivery_types' => ['options' => [[
                    'deliveryType' => 'DeliveryHome',
                    'branchId' => 'branch-1',
                ], [
                    'deliveryType' => 'B2B',
                    'branchId' => 'branch-2',
                ]]],
                'silpo_list_branches' => ['branches' => [[
                    'branchId' => 'branch-1',
                    'name' => 'Сільпо на Хрещатику',
                ]]],
                default => ['updated' => true],
            };

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [
                    'content' => $toolError
                        ? [['type' => 'text', 'text' => 'Door is closed. Bearer private-token']]
                        : [],
                    'isError' => $toolError,
                    'structuredContent' => $toolError ? null : $structuredContent,
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

    public function test_text_search_defaults_to_and_caps_results_at_thirty(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);
        $cart = $this->cart();

        $gateway->searchProducts('secret-token', $cart, 'перець');
        $gateway->searchProducts('secret-token', $cart, 'вода', 100);

        $limits = Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_find_products_batch',
        )->map(fn (array $record): int => (int) data_get(
            $record[0]->data(),
            'params.arguments.limit',
        ))->values()->all();

        $this->assertSame([30, 30], $limits);
    }

    public function test_ready_cart_reads_call_known_tools_without_listing_the_manifest(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
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

            if ($method === 'tools/call'
                && data_get($payload, 'params.name') === 'silpo_get_my_shopping_cart') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => [
                        'content' => [['type' => 'text', 'text' => 'Resource not found']],
                        'isError' => true,
                    ],
                ]);
            }

            return Http::response('', 500);
        });

        $cart = $this->app->make(McpSilpoCartGateway::class)
            ->getReadyCart('secret-token');

        $this->assertNull($cart);
        $this->assertCount(0, Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'method') === 'tools/list',
        ));
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_get_my_shopping_cart',
        ));
    }

    public function test_cart_reset_requires_the_live_clear_schema_and_calls_snapshot_clear_then_immediate_readback(): void
    {
        $cleared = false;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$cleared) {
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
                    'result' => ['tools' => [[
                        'name' => 'silpo_get_my_shopping_cart',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ], [
                        'name' => 'silpo_get_shopping_cart_by_id',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ], [
                        'name' => 'silpo_clear_shopping_cart',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => ['shoppingCartId' => ['type' => 'string']],
                            'required' => ['shoppingCartId'],
                        ],
                    ]]],
                ]);
            }

            $toolName = data_get($payload, 'params.name');
            $structuredContent = match ($toolName) {
                'silpo_get_my_shopping_cart' => ['shoppingCartId' => 'cart-1'],
                'silpo_clear_shopping_cart' => tap(['cleared' => true], function () use (&$cleared): void {
                    $cleared = true;
                }),
                'silpo_get_shopping_cart_by_id' => ['cart' => [
                    'deliveryType' => 'DeliveryHome',
                    'address' => ['addressType' => 'delivery', 'address' => 'Київ, Хрещатик, 1'],
                    'timeslot' => ['start' => '2026-08-26T10:00:00Z', 'end' => '2026-08-26T11:00:00Z'],
                    'shipments' => [[
                        'companyId' => 'company-1',
                        'branchId' => 'branch-1',
                        'products' => $cleared ? [] : [['productId' => 'water-1', 'quantity' => 2]],
                    ]],
                    'calculation' => ['validations' => [], 'totalAfterDiscounts' => $cleared ? 0 : 60],
                ]],
                default => [],
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

        $gateway = $this->app->make(McpSilpoCartGateway::class);
        $before = $gateway->getFulfilmentSnapshot('secret-token');
        $after = $gateway->clearCartProducts('secret-token', 'cart-1');

        $this->assertSame(1, $before?->itemsCount());
        $this->assertTrue($after->isEmpty());
        $calls = Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'method') === 'tools/call',
        )->map(fn (array $record): string => (string) data_get($record[0]->data(), 'params.name'))
            ->values()
            ->all();
        $this->assertSame([
            'silpo_get_my_shopping_cart',
            'silpo_get_shopping_cart_by_id',
            'silpo_clear_shopping_cart',
            'silpo_get_shopping_cart_by_id',
        ], $calls);
        $clearRequest = Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_clear_shopping_cart',
        )->sole()[0];
        $this->assertSame(
            ['shoppingCartId' => 'cart-1'],
            data_get($clearRequest->data(), 'params.arguments'),
        );
    }

    public function test_cart_reset_stops_before_clear_when_the_live_schema_changes(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
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
                    'result' => ['tools' => [[
                        'name' => 'silpo_clear_shopping_cart',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'shoppingCartId' => ['type' => 'string'],
                                'reason' => ['type' => 'string'],
                            ],
                            'required' => ['shoppingCartId', 'reason'],
                        ],
                    ], [
                        'name' => 'silpo_get_shopping_cart_by_id',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ]]],
                ]);
            }

            return Http::response('', 500);
        });

        try {
            $this->app->make(McpSilpoCartGateway::class)
                ->clearCartProducts('secret-token', 'cart-1');
            $this->fail('Expected the changed reset schema to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Сільпо змінило правила очищення кошика. Гусь нічого не видаляв.',
                $exception->getMessage(),
            );
        }

        $this->assertCount(0, Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'method') === 'tools/call',
        ));
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

    public function test_tool_manifest_is_discovered_once_per_gateway_instance_and_fresh_for_a_new_instance(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);

        $gateway->findDeliveryAddresses('secret-token', 'Київ, Хрещатик, 1');
        $gateway->findDeliveryAddresses('secret-token', 'Київ, Саксаганського, 57-Б');

        $manifestCalls = Http::recorded(
            fn (Request $request): bool => $request->data()['method'] === 'tools/list',
        );
        $this->assertCount(1, $manifestCalls);

        $freshGateway = $this->app->make(McpSilpoCartGateway::class);
        $freshGateway->findDeliveryAddresses('secret-token', 'Львів, проспект Свободи, 10');

        $manifestCalls = Http::recorded(
            fn (Request $request): bool => $request->data()['method'] === 'tools/list',
        );
        $this->assertCount(2, $manifestCalls);
    }

    public function test_silpo_tool_error_details_are_preserved_but_credentials_are_redacted(): void
    {
        try {
            $this->app->make(McpSilpoCartGateway::class)
                ->findDeliveryAddresses('secret-token', 'error-with-details');
            $this->fail('Expected the Silpo tool error to be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Сільпо не завершило операцію: пошук адреси. Відповідь Сільпо: Door is closed. Bearer [приховано]',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('private-token', $exception->getMessage());
        }
    }

    public function test_transient_manifest_failure_is_retried_once_on_a_fresh_client(): void
    {
        $manifestAttempts = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$manifestAttempts) {
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
                $manifestAttempts++;

                if ($manifestAttempts === 1) {
                    return Http::response(['message' => 'temporary'], 502);
                }

                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $payload['id'],
                    'result' => ['tools' => [[
                        'name' => 'silpo_find_address',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ]]],
                ]);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => [
                    'content' => [],
                    'isError' => false,
                    'structuredContent' => ['addresses' => [[
                        'address' => 'Київ, Хрещатик, 1',
                        'latitude' => 50.45,
                        'longitude' => 30.52,
                    ]]],
                ],
            ]);
        });

        $addresses = $this->app->make(McpSilpoCartGateway::class)
            ->findDeliveryAddresses('secret-token', 'Київ, Хрещатик, 1');

        $this->assertSame('Київ, Хрещатик, 1', data_get($addresses, '0.address'));
        $this->assertSame(2, $manifestAttempts);
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_find_address',
        ));
    }

    public function test_manifest_retry_exhaustion_returns_a_branded_failure_without_calling_a_tool(): void
    {
        $manifestAttempts = 0;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use (&$manifestAttempts) {
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
                $manifestAttempts++;

                return Http::response(['message' => 'temporary'], 503);
            }

            return Http::response('', 500);
        });

        try {
            $this->app->make(McpSilpoCartGateway::class)
                ->findDeliveryAddresses('secret-token', 'Київ, Хрещатик, 1');
            $this->fail('Expected manifest discovery to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Сільпо двічі не відкрило Гусю список маршрутів. Спробуйте ще раз за хвилину.',
                $exception->getMessage(),
            );
        }

        $this->assertSame(2, $manifestAttempts);
        $this->assertCount(0, Http::recorded(
            fn (Request $request): bool => ($request->data()['method'] ?? null) === 'tools/call',
        ));
    }

    public function test_fulfilment_filters_unsupported_delivery_types_and_passes_nova_poshta_office_hint(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);

        $types = $gateway->getAvailableDeliveryTypes('secret-token', 50.45, 30.52);
        $gateway->findNovaPoshtaOffices('secret-token', 'settlement-1', 'поштомат 28122');

        $this->assertSame(['DeliveryHome'], collect($types)->pluck('deliveryType')->all());
        $officeCall = Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_find_nova_poshta_offices',
        )->sole()[0];
        $this->assertSame('settlement-1', data_get($officeCall->data(), 'params.arguments.settlementId'));
        $this->assertSame('поштомат 28122', data_get($officeCall->data(), 'params.arguments.title'));
    }

    public function test_fulfilment_branch_search_respects_the_silpo_limit_and_compatibility_filters(): void
    {
        $gateway = $this->app->make(McpSilpoCartGateway::class);

        $pickupBranches = $gateway->getFulfilmentBranches('secret-token', true, false);
        $novaPoshtaBranches = $gateway->getFulfilmentBranches('secret-token', false, true);

        $this->assertSame('branch-1', data_get($pickupBranches, '0.branchId'));
        $this->assertSame('branch-1', data_get($novaPoshtaBranches, '0.branchId'));

        $branchCalls = Http::recorded(
            fn (Request $request): bool => data_get($request->data(), 'params.name') === 'silpo_list_branches',
        )->values();

        $this->assertCount(2, $branchCalls);
        $this->assertSame([
            'limit' => 500,
            'hasPickup' => true,
        ], data_get($branchCalls[0][0]->data(), 'params.arguments'));
        $this->assertSame([
            'limit' => 500,
            'hasNP' => true,
        ], data_get($branchCalls[1][0]->data(), 'params.arguments'));
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

    public function test_home_fulfilment_update_sends_target_branch_without_rewriting_cart_address_or_shipments(): void
    {
        $updateArguments = null;
        $address = [
            'addressType' => 'flat',
            'city' => 'Київ',
            'street' => 'Олександра Довженка',
            'house' => '4-А',
            'latitude' => '50.456',
            'longitude' => '30.445',
        ];
        $currentShipments = [['companyId' => 'company-1', 'branchId' => 'branch-1']];

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($address, $currentShipments, &$updateArguments) {
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
                    'result' => ['tools' => [[
                        'name' => 'silpo_get_shopping_cart_by_id',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ], [
                        'name' => 'silpo_update_shopping_cart',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'shoppingCartId' => ['type' => 'string'],
                                'deliveryType' => ['type' => 'string'],
                                'timeslot' => ['type' => 'object'],
                                'address' => ['type' => 'object'],
                                'shipments' => ['type' => 'array'],
                                'branchId' => ['type' => 'string'],
                            ],
                            'required' => [
                                'shoppingCartId',
                                'deliveryType',
                                'timeslot',
                                'address',
                                'shipments',
                            ],
                        ],
                    ]]],
                ]);
            }

            $toolName = data_get($payload, 'params.name');

            if ($toolName === 'silpo_update_shopping_cart') {
                $updateArguments = data_get($payload, 'params.arguments');
                $structuredContent = ['updated' => true];
            } else {
                $structuredContent = ['cart' => [
                    'deliveryType' => 'WideAssortDelivery',
                    'timeslot' => [
                        'start' => '2026-08-25T10:00:00Z',
                        'end' => '2026-08-25T11:00:00Z',
                    ],
                    'address' => $address,
                    'shipments' => [[...$currentShipments[0], 'branchId' => 'branch-2']],
                    'calculation' => ['validations' => [], 'totalAfterDiscounts' => 50],
                ]];
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

        $snapshot = $this->app->make(McpSilpoCartGateway::class)->updateFulfilment(
            accessToken: 'secret-token',
            cartId: 'cart-1',
            deliveryType: 'WideAssortDelivery',
            slotStart: '2026-08-25T10:00:00Z',
            slotEnd: '2026-08-25T11:00:00Z',
            address: $address,
            shipments: $currentShipments,
            targetBranchId: 'branch-2',
        );

        $this->assertNotNull($snapshot);
        $this->assertSame('branch-2', data_get($snapshot->routeShipments(), '0.branchId'));
        $this->assertSame($address, data_get($updateArguments, 'address'));
        $this->assertSame($currentShipments, data_get($updateArguments, 'shipments'));
        $this->assertSame('branch-2', data_get($updateArguments, 'branchId'));
    }

    public function test_fulfilment_update_stops_before_writing_when_the_live_schema_gains_a_required_field(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
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
                    'result' => ['tools' => [[
                        'name' => 'silpo_get_shopping_cart_by_id',
                        'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                    ], [
                        'name' => 'silpo_update_shopping_cart',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => (object) [],
                            'required' => [
                                'shoppingCartId',
                                'deliveryType',
                                'timeslot',
                                'address',
                                'shipments',
                                'surpriseRequiredField',
                            ],
                        ],
                    ]]],
                ]);
            }

            return Http::response([
                'jsonrpc' => '2.0',
                'id' => $payload['id'],
                'result' => ['content' => [], 'isError' => false, 'structuredContent' => []],
            ]);
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Сільпо змінило правила маршруту. Гусь зупинився, щоб нічого не вигадувати.');

        $this->app->make(McpSilpoCartGateway::class)->updateFulfilment(
            accessToken: 'secret-token',
            cartId: 'cart-1',
            deliveryType: 'DeliveryHome',
            slotStart: '2026-08-25T10:00:00Z',
            slotEnd: '2026-08-25T11:00:00Z',
            address: ['addressType' => 'delivery'],
            shipments: [['companyId' => 'company-1', 'branchId' => 'branch-1']],
        );
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
