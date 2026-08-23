<?php

namespace Tests\Unit;

use App\Data\SilpoCartContextData;
use App\Services\McpSilpoCartGateway;
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

            $toolName = data_get($payload, 'params.name');
            $structuredContent = $toolName === 'silpo_find_products_batch'
                ? ['queries' => [[
                    'query' => data_get($payload, 'params.arguments.products.0'),
                    'products' => [['id' => 'product-1', 'name' => 'Вода']],
                ]]]
                : ['updated' => true];

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
