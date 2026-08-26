<?php

namespace Tests\Unit;

use App\Data\SilpoCartContextData;
use App\HarnessEntryKind;
use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use App\Services\OpenAiSilpoCartRunner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;
use UnexpectedValueException;

class OpenAiSilpoCartRunnerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'openai-test-key',
            'services.silpo_mcp.url' => 'https://silpo-mcp.test/mcp',
            'services.silpo_cart_harness.mode' => 'agentic',
            'services.silpo_cart_harness.model' => 'gpt-5.6-luna',
            'services.silpo_cart_harness.reasoning_effort' => 'high',
            'services.silpo_cart_harness.request_timeout' => 150,
            'services.silpo_cart_harness.max_tool_calls_per_need' => 12,
        ]);
        Http::preventStrayRequests();
    }

    public function test_model_native_catalog_request_is_bounded_and_php_accepts_only_observed_product(): void
    {
        Http::fake(['*' => Http::response($this->selectionResponse('water-1'))]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $this->selectionContext(),
        );

        $this->assertSame('water-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame(2.0, data_get($result->selectedItem, 'quantity'));
        $this->assertSame(1, $result->toolCallCount);
        $this->assertSame('Вода негазована', data_get($result->attempts, '0.query'));
        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();
            $runtime = (string) data_get($payload, 'input.0.content.0.text');

            return data_get($payload, 'model') === 'gpt-5.6-luna'
                && data_get($payload, 'reasoning.effort') === 'high'
                && data_get($payload, 'store') === false
                && data_get($payload, 'parallel_tool_calls') === false
                && data_get($payload, 'max_tool_calls') === 12
                && data_get($payload, 'tools.0.type') === 'mcp'
                && data_get($payload, 'tools.0.server_url') === 'https://silpo-mcp.test/mcp'
                && data_get($payload, 'tools.0.authorization') === 'silpo-secret-token'
                && data_get($payload, 'tools.0.require_approval') === 'never'
                && str_contains(
                    (string) data_get($payload, 'instructions'),
                    'current_need.search_queries у переданому порядку',
                )
                && data_get($payload, 'tools.0.allowed_tools') === [
                    'silpo_find_products_batch',
                    'silpo_get_products',
                    'silpo_get_product_details',
                    'silpo_get_categories_tree',
                    'silpo_get_product_sets',
                    'silpo_get_replacements',
                ]
                && data_get($payload, 'include') === ['reasoning.encrypted_content']
                && ! str_contains($runtime, 'silpo-secret-token');
        });
    }

    public function test_agentic_discovery_searches_the_prepared_retailer_identity_before_menu_purpose(): void
    {
        $preparedIdentity = 'Ошийок свинячий охолоджений';
        $context = $this->selectionContext();
        $context['current_need'] = [
            ...$context['current_need'],
            'name' => $preparedIdentity,
            'category' => 'food',
            'quantity' => 2.2,
            'unit' => 'кг',
            'note' => 'Для приготування свинячого шашлику без маринаду.',
            'search_queries' => ['свинячий ошийок', 'ошийок свинина'],
        ];
        $context['all_needs'] = [$context['current_need']];
        $response = $this->selectionResponse('pork-1');
        $arguments = json_decode(
            (string) data_get($response, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = [$preparedIdentity];
        $response['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $output = json_decode(
            (string) data_get($response, 'output.1.output'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $output['queries'][0]['query'] = $preparedIdentity;
        $output['queries'][0]['products'][0] = [
            ...$output['queries'][0]['products'][0],
            'id' => 'pork-1',
            'name' => 'Свинячий ошийок домашній',
            'slug' => 'svyniachyi-oshyiok-domashnii',
            'price' => 299,
            'stock' => 8.4,
            'weighted' => true,
            'step' => 0.1,
            'displayRatio' => '1 кг',
        ];
        $response['output'][1]['output'] = json_encode($output, JSON_THROW_ON_ERROR);
        $decision = $this->decision('pork-1');
        $decision['quantity'] = 2.2;
        $decision['reason'] = 'Exact raw pork neck.';
        $response['output'][3]['content'][0]['text'] = json_encode($decision, JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($response)]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $context,
        );

        $this->assertSame('pork-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame($preparedIdentity, data_get($result->attempts, '0.query'));
        $this->assertStringNotContainsString(
            'для шашлику',
            Str::lower((string) data_get($result->attempts, '0.query')),
        );
    }

    public function test_unseen_model_product_is_rejected_after_the_single_repair_continuation(): void
    {
        $repairResponse = $this->selectionResponse('still-invented');
        $repairResponse['output'] = array_slice($repairResponse['output'], 2);
        Http::fakeSequence()
            ->push($this->selectionResponse('invented-product'))
            ->push($repairResponse);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not observed');

        try {
            app(OpenAiSilpoCartRunner::class)->selectNeed(
                'silpo-secret-token',
                $this->cart(),
                $this->selectionContext(),
            );
        } finally {
            Http::assertSentCount(2);
            $secondPayload = Http::recorded()[1][0]->data();
            $this->assertArrayNotHasKey('max_tool_calls', $secondPayload);
            $this->assertArrayNotHasKey('tools', $secondPayload);
            $this->assertSame('mcp_list_tools', data_get($secondPayload, 'input.1.type'));
            $this->assertSame('mcp_call', data_get($secondPayload, 'input.2.type'));
            $this->assertSame('reasoning', data_get($secondPayload, 'input.3.type'));
            $this->assertStringContainsString(
                'Не викликай жодних MCP tools',
                (string) data_get($secondPayload, 'instructions'),
            );
            $this->assertStringContainsString(
                'validation_error',
                (string) data_get($secondPayload, 'input.5.content.0.text'),
            );
        }
    }

    public function test_proven_exact_evidence_gets_a_decision_only_repair_without_redundant_mcp_calls(): void
    {
        $repairResponse = $this->selectionResponse('water-1');
        $repairResponse['output'] = array_slice($repairResponse['output'], 2);
        Http::fakeSequence()
            ->push($this->selectionResponse('invented-product'))
            ->push($repairResponse);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $this->selectionContext(),
        );

        $this->assertSame('water-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame(1, $result->toolCallCount);
        Http::assertSentCount(2);
        $repairPayload = Http::recorded()[1][0]->data();
        $this->assertArrayNotHasKey('max_tool_calls', $repairPayload);
        $this->assertArrayNotHasKey('tools', $repairPayload);
        $this->assertStringContainsString(
            'Не викликай жодних MCP tools',
            (string) data_get($repairPayload, 'instructions'),
        );
    }

    public function test_malformed_multi_need_search_restarts_with_clean_evidence_and_live_progress(): void
    {
        $malformedResponse = $this->selectionResponse('water-1');
        $arguments = json_decode(
            (string) data_get($malformedResponse, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = ['Вода негазована', 'Серветки'];
        $malformedResponse['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $repairResponse = $this->selectionResponse('water-1');
        $progress = [];
        $requestCount = 0;

        Http::fake(function () use (
            &$progress,
            &$requestCount,
            $malformedResponse,
            $repairResponse,
        ) {
            if ($requestCount++ === 0) {
                $this->assertSame([['searching', 'Вода негазована']], $progress);

                return Http::response($malformedResponse);
            }

            $this->assertSame([
                ['searching', 'Вода негазована'],
                ['retry', null],
            ], $progress);

            return Http::response($repairResponse);
        });

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $this->selectionContext(),
            onProgress: function (string $kind, ?string $query) use (&$progress): void {
                $progress[] = [$kind, $query];
            },
        );

        $this->assertSame('water-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame(2, $result->toolCallCount);
        $this->assertCount(1, $result->attempts);
        $this->assertSame('Вода негазована', data_get($result->attempts, '0.query'));
        Http::assertSentCount(2);
        $repairPayload = Http::recorded()[1][0]->data();
        $this->assertSame(11, data_get($repairPayload, 'max_tool_calls'));
        $this->assertStringContainsString(
            'Попередні MCP calls не є локально допустимим доказом',
            (string) data_get($repairPayload, 'instructions'),
        );
        $this->assertNull(data_get(
            json_decode((string) data_get($repairPayload, 'input.5.content.0.text'), true),
            'instruction',
        ));
    }

    public function test_later_native_batch_search_accepts_bounded_variants_for_the_same_need(): void
    {
        $response = $this->selectionResponse('water-1');
        $exactCall = $response['output'][1];
        $exactOutput = json_decode((string) $exactCall['output'], true, flags: JSON_THROW_ON_ERROR);
        $product = data_get($exactOutput, 'queries.0.products.0');
        $exactOutput['queries'][0]['products'] = [];
        $response['output'][1]['output'] = json_encode($exactOutput, JSON_THROW_ON_ERROR);
        $variantCall = $exactCall;
        $variantArguments = json_decode((string) $variantCall['arguments'], true, flags: JSON_THROW_ON_ERROR);
        $variantArguments['products'] = ['вода питна', 'вода без газу'];
        $variantCall['arguments'] = json_encode($variantArguments, JSON_THROW_ON_ERROR);
        $variantCall['output'] = json_encode([
            'success' => true,
            'queries' => [
                ['query' => 'вода питна', 'products' => [$product]],
                ['query' => 'вода без газу', 'products' => []],
            ],
        ], JSON_THROW_ON_ERROR);
        array_splice($response['output'], 2, 0, [$variantCall]);
        Http::fake(['*' => Http::response($response)]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $this->selectionContext(),
        );

        $this->assertSame('water-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame(2, $result->toolCallCount);
        $this->assertSame(
            ['Вода негазована', 'вода питна', 'вода без газу'],
            collect($result->attempts)->pluck('query')->all(),
        );
    }

    public function test_commit_waits_for_exact_approval_then_performs_one_write_and_readback(): void
    {
        $products = $this->approvedProducts();
        Http::fakeSequence()
            ->push($this->approvalResponse($products))
            ->push($this->commitContinuationResponse($products));

        $verified = app(OpenAiSilpoCartRunner::class)->commitApproved(
            'silpo-secret-token',
            $this->cart(),
            $products,
        );

        $this->assertSame('cart-1', $verified->cartId);
        $this->assertSame('water-1', data_get($verified->items, '0.product_id'));
        $this->assertSame(2.0, data_get($verified->items, '0.quantity'));
        Http::assertSentCount(2);
        $first = Http::recorded()[0][0]->data();
        $second = Http::recorded()[1][0]->data();
        $this->assertSame(2, data_get($first, 'max_tool_calls'));
        $this->assertSame([
            'never' => ['tool_names' => ['silpo_get_shopping_cart_by_id']],
        ], data_get($first, 'tools.0.require_approval'));
        $this->assertSame('mcp_approval_request', data_get($second, 'input.1.type'));
        $this->assertSame('mcp_approval_response', data_get($second, 'input.2.type'));
        $this->assertTrue(data_get($second, 'input.2.approve'));
    }

    public function test_tampered_commit_arguments_are_rejected_before_approval(): void
    {
        $products = $this->approvedProducts();
        $tampered = $products;
        $tampered[0]['quantity'] = 3;
        Http::fake(['*' => Http::response($this->approvalResponse($tampered))]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('differ from the confirmed staged set');

        try {
            app(OpenAiSilpoCartRunner::class)->commitApproved(
                'silpo-secret-token',
                $this->cart(),
                $products,
            );
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_harness_trace_proves_model_native_catalog_approval_write_and_readback_without_secrets(): void
    {
        $event = Event::factory()->ready()->create();
        $harness = HarnessRun::factory()->for($event)->create([
            'type' => HarnessRunType::SilpoCart,
            'status' => HarnessRunStatus::Running,
            'finished_at' => null,
        ]);
        $products = $this->approvedProducts();
        Http::fakeSequence()
            ->push($this->selectionResponse('water-1'))
            ->push($this->approvalResponse($products))
            ->push($this->commitContinuationResponse($products));
        $runner = app(OpenAiSilpoCartRunner::class);

        $runner->selectNeed('silpo-secret-token', $this->cart(), $this->selectionContext(), $harness);
        $runner->commitApproved('silpo-secret-token', $this->cart(), $products, $harness);

        $entries = $harness->entries()->orderBy('sequence')->get();
        $nativeItems = $entries
            ->where('kind', HarnessEntryKind::Mcp)
            ->pluck('metadata.response_item_type')
            ->values()
            ->all();
        $toolNames = $entries
            ->where('kind', HarnessEntryKind::Mcp)
            ->pluck('metadata.tool_name')
            ->filter()
            ->values()
            ->all();
        $this->assertContains('mcp_list_tools', $nativeItems);
        $this->assertContains('mcp_approval_request', $nativeItems);
        $this->assertSame([
            'silpo_find_products_batch',
            'silpo_add_or_update_cart_products',
            'silpo_add_or_update_cart_products',
            'silpo_get_shopping_cart_by_id',
        ], $toolNames);
        $this->assertTrue($entries->contains(
            fn ($entry): bool => data_get($entry->metadata, 'approved') === true,
        ));
        $this->assertSame('model_native_mcp', data_get($harness->fresh()->metadata, 'execution_source'));
        $this->assertSame(3, data_get($harness->fresh()->metadata, 'native_mcp_tool_calls'));
        $this->assertSame(
            '[REDACTED]',
            data_get($entries->firstWhere('kind', HarnessEntryKind::Llm)?->request_payload, 'tools.0.authorization'),
        );
        $serializedTrace = json_encode($entries->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('silpo-secret-token', $serializedTrace);
        $this->assertStringNotContainsString('encrypted-reasoning', $serializedTrace);
    }

    private function cart(): SilpoCartContextData
    {
        return new SilpoCartContextData(
            cartId: 'cart-1',
            deliveryType: 'DeliveryHome',
            branchId: 'branch-1',
            companyId: 'company-1',
            slotStart: '2026-08-27T10:00:00+03:00',
            slotEnd: '2026-08-27T11:00:00+03:00',
            items: [],
            validations: [],
            slot: [
                'start' => '2026-08-27T10:00:00+03:00',
                'end' => '2026-08-27T11:00:00+03:00',
            ],
            totalAfterDiscounts: 0,
        );
    }

    /** @return array<string, mixed> */
    private function selectionContext(): array
    {
        $need = [
            'key' => 'n_01',
            'name' => 'Вода негазована',
            'category' => 'water',
            'quantity' => 3,
            'unit' => 'л',
            'note' => '',
            'search_queries' => ['вода', 'негазована вода'],
            'status' => 'pending',
            'attempts' => [],
        ];

        return [
            'cart_run_id' => 11,
            'mode' => 'assisted',
            'people_count' => 6,
            'food_constraints' => [],
            'product_constraints' => [],
            'current_need' => $need,
            'all_needs' => [$need],
            'staged_items' => [],
            'event_context' => [],
            'shopping_plan' => [],
            'human_answer' => null,
            'native_tool_calls_used' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function selectionResponse(string $selectedProductId): array
    {
        return [
            'model' => 'gpt-5.6-luna-2026-08-01',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 40, 'total_tokens' => 140],
            'output' => [
                ['type' => 'mcp_list_tools', 'server_label' => 'silpo', 'status' => 'completed'],
                [
                    'type' => 'mcp_call',
                    'server_label' => 'silpo',
                    'name' => 'silpo_find_products_batch',
                    'status' => 'completed',
                    'arguments' => json_encode([
                        'branchId' => 'branch-1',
                        'deliveryType' => 'DeliveryHome',
                        'timeslotStart' => '2026-08-27T10:00:00+03:00',
                        'timeslotEnd' => '2026-08-27T11:00:00+03:00',
                        'products' => ['Вода негазована'],
                        'limit' => 30,
                    ], JSON_THROW_ON_ERROR),
                    'output' => json_encode([
                        'success' => true,
                        'queries' => [[
                            'query' => 'Вода негазована',
                            'products' => [[
                                'id' => 'water-1',
                                'name' => 'Вода негазована 1,5 л',
                                'slug' => 'water-15l',
                                'price' => 25,
                                'stock' => 20,
                                'available' => true,
                                'weighted' => false,
                                'step' => 1,
                                'displayRatio' => '1,5 л',
                                'companyId' => 'company-1',
                                'branchId' => 'branch-1',
                            ]],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
                ['type' => 'reasoning', 'encrypted_content' => 'encrypted-reasoning'],
                [
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode($this->decision($selectedProductId), JSON_THROW_ON_ERROR),
                    ]],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function decision(string $selectedProductId): array
    {
        return [
            'action' => 'select',
            'selected_product_id' => $selectedProductId,
            'query' => null,
            'quantity' => 2,
            'reason' => 'Exact water product.',
            'question' => null,
            'audit' => [
                'complete' => true,
                'covered_need_keys' => ['n_01'],
                'remaining_need_keys' => [],
                'enough_for_people' => true,
                'warnings' => [],
                'revisit_need_key' => null,
                'revisit_query' => null,
                'question' => null,
            ],
            'allow_catalog_fallback' => false,
            'candidate_matches_required_product' => true,
            'safety_evidence' => 'not_required',
            'is_replacement' => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function approvedProducts(): array
    {
        return [[
            'productId' => 'water-1',
            'companyId' => 'company-1',
            'branchId' => 'branch-1',
            'quantity' => 2,
            'addQuantity' => false,
        ]];
    }

    /** @param array<int, array<string, mixed>> $products @return array<string, mixed> */
    private function approvalResponse(array $products): array
    {
        return [
            'model' => 'gpt-5.6-luna-2026-08-01',
            'output' => [[
                'type' => 'mcp_approval_request',
                'id' => 'approval-1',
                'server_label' => 'silpo',
                'name' => 'silpo_add_or_update_cart_products',
                'arguments' => json_encode([
                    'shoppingCartId' => 'cart-1',
                    'products' => $products,
                ], JSON_THROW_ON_ERROR),
            ]],
        ];
    }

    /** @param array<int, array<string, mixed>> $products @return array<string, mixed> */
    private function commitContinuationResponse(array $products): array
    {
        return [
            'model' => 'gpt-5.6-luna-2026-08-01',
            'output' => [
                [
                    'type' => 'mcp_call',
                    'server_label' => 'silpo',
                    'name' => 'silpo_add_or_update_cart_products',
                    'status' => 'completed',
                    'arguments' => json_encode([
                        'shoppingCartId' => 'cart-1',
                        'products' => $products,
                    ], JSON_THROW_ON_ERROR),
                    'output' => json_encode(['success' => true], JSON_THROW_ON_ERROR),
                ],
                [
                    'type' => 'mcp_call',
                    'server_label' => 'silpo',
                    'name' => 'silpo_get_shopping_cart_by_id',
                    'status' => 'completed',
                    'arguments' => json_encode(['shoppingCartId' => 'cart-1'], JSON_THROW_ON_ERROR),
                    'output' => json_encode($this->readbackPayload(), JSON_THROW_ON_ERROR),
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function readbackPayload(): array
    {
        return ['cart' => [
            'deliveryType' => 'DeliveryHome',
            'timeslot' => [
                'start' => '2026-08-27T10:00:00+03:00',
                'end' => '2026-08-27T11:00:00+03:00',
            ],
            'address' => ['city' => 'Київ', 'street' => 'Хрещатик', 'house' => '1'],
            'shipments' => [[
                'companyId' => 'company-1',
                'branchId' => 'branch-1',
                'products' => [[
                    'productId' => 'water-1',
                    'companyId' => 'company-1',
                    'branchId' => 'branch-1',
                    'name' => 'Вода негазована 1,5 л',
                    'quantity' => 2,
                    'price' => 25,
                    'stock' => 20,
                    'addToBasketStep' => 1,
                    'total' => 50,
                ]],
            ]],
            'calculation' => ['totalAfterDiscounts' => 50, 'validations' => []],
        ]];
    }
}
