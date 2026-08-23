<?php

namespace Tests\Unit;

use App\Services\CartProductDecisionService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

class CartProductDecisionServiceTest extends TestCase
{
    public function test_preparation_schema_uses_the_openai_supported_array_subset(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $schema = $reflection->getMethod('preparationSchema')->invoke($service);
        $searchQueries = data_get($schema, 'properties.needs.items.properties.search_queries');

        $this->assertIsArray($searchQueries);
        $this->assertArrayNotHasKey('uniqueItems', $searchQueries);
        $this->assertSame(['type' => 'string'], $searchQueries['items']);
    }

    public function test_preparation_batches_large_plans_without_renumbering_source_indexes(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
        ]);
        Http::fakeSequence()
            ->push($this->openAiPreparationResponse(range(0, 5)))
            ->push($this->openAiPreparationResponse(range(6, 7)));
        $items = collect(range(0, 7))->map(fn (int $index): array => [
            'name' => $this->productName($index),
            'category' => 'other',
            'quantity' => 1,
            'unit' => 'шт',
            'note' => '',
        ])->all();

        $preparation = app(CartProductDecisionService::class)->prepare([], ['items' => $items]);

        $this->assertCount(8, $preparation->needs);
        $this->assertSame(range(0, 7), array_column($preparation->needs, 'source_index'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            '"source_index": 6',
        ));
        $secondPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'input.0.content.0.text');
        $this->assertStringContainsString('"active_source_indexes": [', $secondPrompt);
        $this->assertStringContainsString('"name": "яблука"', $secondPrompt);
        $this->assertStringContainsString('"name": "серветки"', $secondPrompt);
        $this->assertStringContainsString('повертай needs ЛИШЕ для active_source_indexes', $secondPrompt);
    }

    public function test_preparation_ignores_items_emitted_outside_the_active_batch(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
        ]);
        Http::fakeSequence()
            ->push($this->openAiPreparationResponse(range(0, 5)))
            ->push($this->openAiPreparationResponse([0, 6]));
        $items = collect(range(0, 6))->map(fn (int $index): array => [
            'name' => $this->productName($index),
            'category' => 'other',
            'quantity' => 1,
            'unit' => 'шт',
            'note' => '',
        ])->all();

        $preparation = app(CartProductDecisionService::class)->prepare([], ['items' => $items]);

        $this->assertCount(7, $preparation->needs);
        $this->assertSame(range(0, 6), array_column($preparation->needs, 'source_index'));
    }

    public function test_decision_audit_keys_are_normalized_against_authoritative_needs(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $payload = [
            'audit' => [
                'covered_need_keys' => ['n_01', 'invented'],
                'remaining_need_keys' => ['n_01'],
                'revisit_need_key' => 'invented',
                'revisit_query' => 'wrong query',
            ],
        ];

        $normalized = $reflection->getMethod('normalizeDecisionPayload')->invoke($service, $payload, [
            'all_needs' => [['key' => 'n_01'], ['key' => 'n_02']],
        ]);

        $this->assertSame(['n_01'], data_get($normalized, 'audit.covered_need_keys'));
        $this->assertSame(['n_02'], data_get($normalized, 'audit.remaining_need_keys'));
        $this->assertNull(data_get($normalized, 'audit.revisit_need_key'));
        $this->assertNull(data_get($normalized, 'audit.revisit_query'));
    }

    public function test_retailer_search_guidance_is_injected_into_preparation_and_decision_prompts(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
            'silpo_catalog_search.prompt_guidance' => [
                'retailer-specific-query-marker',
                'retailer-specific-safety-marker',
            ],
        ]);
        Http::fake([
            '*' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'action' => 'skip',
                            'selected_product_id' => null,
                            'query' => null,
                            'quantity' => null,
                            'reason' => 'No safe candidate.',
                            'question' => null,
                            'audit' => [
                                'complete' => false,
                                'covered_need_keys' => [],
                                'remaining_need_keys' => ['n_01'],
                                'enough_for_people' => false,
                                'warnings' => [],
                                'revisit_need_key' => null,
                                'revisit_query' => null,
                                'question' => null,
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);
        $service = app(CartProductDecisionService::class);
        $preparationPrompt = (new ReflectionClass($service))
            ->getMethod('preparationPrompt')
            ->invoke($service, [], ['items' => []]);

        $service->decide([
            'all_needs' => [['key' => 'n_01']],
        ]);

        $this->assertStringContainsString('retailer-specific-query-marker', $preparationPrompt);
        $this->assertStringContainsString('retailer-specific-safety-marker', $preparationPrompt);
        $this->assertStringContainsString('найближчу альтернативу з тією самою роллю', $preparationPrompt);
        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'retailer-specific-query-marker',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'retailer-specific-safety-marker',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'рольова заміна',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'паковання треба перевірити людині',
        ));
    }

    /** @param array<int, int> $sourceIndexes */
    private function openAiPreparationResponse(array $sourceIndexes): array
    {
        $needs = collect($sourceIndexes)->map(fn (int $index): array => [
            'key' => "model-key-{$index}",
            'source_index' => $index,
            'name' => $this->productName($index),
            'category' => 'other',
            'quantity' => 1,
            'unit' => 'шт',
            'note' => '',
            'search_queries' => [$this->productName($index), "альтернатива {$index}"],
        ])->all();

        return [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode(['needs' => $needs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ];
    }

    private function productName(int $index): string
    {
        return [
            'яблука',
            'груші',
            'кабачки',
            'печериці',
            'хлібці',
            'помідори',
            'огірки',
            'серветки',
        ][$index];
    }
}
