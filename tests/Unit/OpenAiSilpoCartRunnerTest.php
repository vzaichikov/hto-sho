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
                && str_contains(
                    (string) data_get($payload, 'instructions'),
                    'limit від 1 до 30',
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

    public function test_plain_chilled_chicken_normalizes_the_models_unverified_allergen_guess(): void
    {
        $context = $this->selectionContext();
        $context['event_context'] = ['summary' => 'Сильна алергія на арахіс.'];
        $context['current_need'] = [
            ...$context['current_need'],
            'name' => 'Стегно куряче охолоджене',
            'category' => 'food',
            'quantity' => 1.5,
            'unit' => 'кг',
            'note' => 'Сирі немариновані курячі стегна; перевірити сліди арахісу.',
            'search_queries' => ['куряче стегно охолоджене'],
        ];
        $context['all_needs'] = [$context['current_need']];
        $response = $this->selectionResponse('chicken-1');
        $arguments = json_decode(
            (string) data_get($response, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = ['Стегно куряче охолоджене'];
        $response['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $output = json_decode(
            (string) data_get($response, 'output.1.output'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $output['queries'][0]['query'] = 'Стегно куряче охолоджене';
        $output['queries'][0]['products'][0] = [
            ...$output['queries'][0]['products'][0],
            'id' => 'chicken-1',
            'name' => 'Куряче стегно домашнє Petit Ja охолоджене',
            'slug' => 'kuriache-stehno-domashnie-petit-ja-okholodzhene',
            'price' => 249,
            'stock' => 3.5,
            'weighted' => true,
            'step' => 0.1,
            'displayRatio' => '1 кг',
        ];
        $response['output'][1]['output'] = json_encode($output, JSON_THROW_ON_ERROR);
        $decision = $this->decision('chicken-1');
        $decision['quantity'] = 1.5;
        $decision['safety_evidence'] = 'unverified';
        $decision['audit']['warnings'] = ['Перевірити паковання на арахіс.'];
        $response['output'][3]['content'][0]['text'] = json_encode($decision, JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($response)]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $context,
        );

        $this->assertSame('not_required', data_get($result->selectedItem, 'safety_evidence'));
        $this->assertNull(data_get($result->selectedItem, 'review_note'));
    }

    public function test_processed_vegan_sausages_with_missing_details_are_staged_as_unverified(): void
    {
        $context = $this->selectionContext();
        $context['event_context'] = ['summary' => 'Спільний стіл без арахісу.'];
        $context['current_need'] = [
            ...$context['current_need'],
            'name' => 'Ковбаски рослинні веганські',
            'category' => 'food',
            'quantity' => 1,
            'unit' => 'пачка',
            'note' => 'Рослинна їжа для гриля; перевірити склад і попередження про арахіс.',
            'search_queries' => ['веганські ковбаски'],
        ];
        $context['all_needs'] = [$context['current_need']];
        $response = $this->selectionResponse('vegan-1');
        $arguments = json_decode(
            (string) data_get($response, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = ['Ковбаски рослинні веганські'];
        $response['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $output = json_decode(
            (string) data_get($response, 'output.1.output'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $output['queries'][0]['query'] = 'Ковбаски рослинні веганські';
        $output['queries'][0]['products'][0] = [
            ...$output['queries'][0]['products'][0],
            'id' => 'vegan-1',
            'name' => 'Сосиски Prema Веганоси рослинні варено-копчені',
            'slug' => 'sosysky-prema-veganosy-roslynni-vareno-kopcheni',
            'price' => 169,
            'stock' => 4,
            'weighted' => false,
            'step' => 1,
            'displayRatio' => '300 г',
        ];
        $response['output'][1]['output'] = json_encode($output, JSON_THROW_ON_ERROR);
        $detailsCall = [
            'type' => 'mcp_call',
            'server_label' => 'silpo',
            'name' => 'silpo_get_product_details',
            'status' => 'completed',
            'arguments' => json_encode([
                'branchId' => 'branch-1',
                'slug' => 'sosysky-prema-veganosy-roslynni-vareno-kopcheni',
                'deliveryType' => 'DeliveryHome',
                'timeslotStart' => '2026-08-27T10:00:00+03:00',
                'timeslotEnd' => '2026-08-27T11:00:00+03:00',
            ], JSON_THROW_ON_ERROR),
            'output' => json_encode([
                'success' => true,
                'product' => ['attributes' => ['Маса' => '300 г']],
            ], JSON_THROW_ON_ERROR),
        ];
        array_splice($response['output'], 2, 0, [$detailsCall]);
        $decision = $this->decision('vegan-1');
        $decision['quantity'] = 1;
        $decision['safety_evidence'] = 'unverified';
        $response['output'][4]['content'][0]['text'] = json_encode($decision, JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($response)]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $context,
        );

        $this->assertSame('vegan-1', data_get($result->selectedItem, 'product_id'));
        $this->assertSame('unverified', data_get($result->selectedItem, 'safety_evidence'));
        $this->assertStringContainsString('❓', (string) data_get($result->selectedItem, 'review_note'));
    }

    public function test_partial_stock_is_staged_only_after_all_declared_queries_are_exhausted(): void
    {
        $context = $this->selectionContext();
        $context['current_need'] = [
            ...$context['current_need'],
            'name' => 'Петрушка свіжа',
            'search_query' => 'Петрушка свіжа',
            'category' => 'food',
            'quantity' => 3,
            'unit' => 'пучки',
            'note' => 'Свіжа зелень.',
            'search_queries' => ['петрушка свіжа', 'свіжа петрушка', 'петрушка зелень'],
        ];
        $context['all_needs'] = [$context['current_need']];
        $response = $this->selectionResponse('parsley-1');
        $arguments = json_decode(
            (string) data_get($response, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = ['Петрушка свіжа'];
        $response['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $exactOutput = json_decode(
            (string) data_get($response, 'output.1.output'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $product = [
            ...$exactOutput['queries'][0]['products'][0],
            'id' => 'parsley-1',
            'name' => 'Петрушка фасована',
            'slug' => 'petrushka-fasovana',
            'price' => 45,
            'stock' => 1,
            'weighted' => false,
            'step' => 1,
            'displayRatio' => '50 г',
        ];
        $exactOutput['queries'][0] = ['query' => 'Петрушка свіжа', 'products' => []];
        $response['output'][1]['output'] = json_encode($exactOutput, JSON_THROW_ON_ERROR);
        $variantCall = $response['output'][1];
        $variantArguments = json_decode(
            (string) data_get($variantCall, 'arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $variantArguments['products'] = ['петрушка свіжа', 'свіжа петрушка', 'петрушка зелень'];
        $variantCall['arguments'] = json_encode($variantArguments, JSON_THROW_ON_ERROR);
        $variantCall['output'] = json_encode([
            'success' => true,
            'queries' => [
                ['query' => 'петрушка свіжа', 'products' => []],
                ['query' => 'свіжа петрушка', 'products' => []],
                ['query' => 'петрушка зелень', 'products' => [$product]],
            ],
        ], JSON_THROW_ON_ERROR);
        array_splice($response['output'], 2, 0, [$variantCall]);
        $decision = $this->decision('parsley-1');
        $decision['quantity'] = 3;
        $response['output'][4]['content'][0]['text'] = json_encode($decision, JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($response)]);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $context,
        );

        $this->assertSame(1.0, data_get($result->selectedItem, 'quantity'));
        $this->assertTrue(data_get($result->selectedItem, 'partial_stock'));
        $this->assertSame(3.0, data_get($result->selectedItem, 'requested_quantity'));
        $this->assertStringContainsString(
            'після вичерпання повних альтернатив',
            (string) data_get($result->selectedItem, 'review_note'),
        );
        $this->assertStringContainsString(
            'лише після цього можна обрати придатний товар із додатним, але недостатнім залишком',
            (string) data_get(Http::recorded(), '0.0.instructions'),
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

    public function test_automatic_mode_skips_after_repair_repeats_an_invalid_catalog_contract(): void
    {
        $context = $this->selectionContext();
        $context['mode'] = 'auto';
        $context['current_need'] = [
            ...$context['current_need'],
            'name' => 'Кукурудза цукрова свіжа в качанах',
            'category' => 'food',
            'quantity' => 6,
            'unit' => 'качанів',
            'search_queries' => ['кукурудза свіжа качан', 'качани кукурудзи'],
        ];
        $context['all_needs'] = [$context['current_need']];
        $initialResponse = $this->freshCornSelectionResponse();
        $repairResponse = $this->freshCornSelectionResponse(action: 'skip');
        $repairArguments = json_decode(
            (string) data_get($repairResponse, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $repairArguments['limit'] = 100;
        $repairResponse['output'][1]['arguments'] = json_encode($repairArguments, JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push($initialResponse)
            ->push($repairResponse);

        $result = app(OpenAiSilpoCartRunner::class)->selectNeed(
            'silpo-secret-token',
            $this->cart(),
            $context,
        );

        $this->assertNull($result->selectedItem);
        $this->assertSame(2, $result->toolCallCount);
        $this->assertSame('Кукурудза цукрова свіжа в качанах', data_get($result->attempts, '0.query'));
        $this->assertFalse($result->audit->complete);
        $this->assertSame(['n_01'], $result->audit->remainingNeedKeys);
        $this->assertStringContainsString('після повторної перевірки', $result->warnings[0]);
        Http::assertSentCount(2);
        $repairPayload = Http::recorded()[1][0]->data();
        $feedback = json_decode(
            (string) data_get($repairPayload, 'input.5.content.0.text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertStringContainsString(
            'preparation-state conflict',
            (string) data_get($feedback, 'validation_error'),
        );
        $this->assertStringContainsString(
            'fresh or unprepared',
            (string) data_get($feedback, 'validation_error'),
        );
        $this->assertStringContainsString(
            'validation_error — це авторитетний feedback',
            (string) data_get($repairPayload, 'instructions'),
        );
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
    private function freshCornSelectionResponse(string $action = 'select'): array
    {
        $response = $this->selectionResponse('corn-grill');
        $arguments = json_decode(
            (string) data_get($response, 'output.1.arguments'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $arguments['products'] = ['Кукурудза цукрова свіжа в качанах'];
        $response['output'][1]['arguments'] = json_encode($arguments, JSON_THROW_ON_ERROR);
        $output = json_decode(
            (string) data_get($response, 'output.1.output'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $output['queries'][0]['query'] = 'Кукурудза цукрова свіжа в качанах';
        $output['queries'][0]['products'][0] = [
            ...$output['queries'][0]['products'][0],
            'id' => 'corn-grill',
            'name' => 'Кукурудза Huercasa гриль',
            'slug' => 'kukurudza-huercasa-gryl',
            'stock' => 16,
            'displayRatio' => '400 г',
        ];
        $response['output'][1]['output'] = json_encode($output, JSON_THROW_ON_ERROR);
        $decision = $this->decision('corn-grill');
        $decision['action'] = $action;
        $decision['reason'] = $action === 'skip'
            ? 'Fresh corn was unavailable, so the need is skipped.'
            : 'Use the packaged grill corn as a replacement.';

        if ($action === 'skip') {
            $decision['selected_product_id'] = null;
            $decision['quantity'] = null;
            $decision['audit']['complete'] = false;
            $decision['audit']['covered_need_keys'] = [];
            $decision['audit']['remaining_need_keys'] = ['n_01'];
            $decision['audit']['enough_for_people'] = false;
            $decision['audit']['revisit_need_key'] = 'n_01';
            $decision['audit']['revisit_query'] = 'Кукурудза цукрова свіжа в качанах';
        }

        $response['output'][3]['content'][0]['text'] = json_encode($decision, JSON_THROW_ON_ERROR);

        return $response;
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
