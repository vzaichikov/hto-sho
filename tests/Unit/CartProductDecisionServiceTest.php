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

    public function test_decision_schema_requires_the_model_to_confirm_product_identity(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $schema = $reflection->getMethod('decisionSchema')->invoke($service);

        $this->assertContains('candidate_matches_required_product', $schema['required']);
        $this->assertContains('is_replacement', $schema['required']);
        $this->assertSame(
            ['type' => 'boolean'],
            data_get($schema, 'properties.candidate_matches_required_product'),
        );
        $this->assertSame(['type' => 'boolean'], data_get($schema, 'properties.is_replacement'));
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

    public function test_final_audit_adds_omitted_need_keys_to_the_remaining_partition(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $payload = [
            'complete' => true,
            'covered_need_keys' => ['n_01', 'invented'],
            'remaining_need_keys' => ['n_01'],
            'enough_for_people' => true,
            'revisit_need_key' => 'n_01',
            'revisit_query' => 'already covered',
        ];

        $normalized = $reflection->getMethod('normalizeAuditPayload')->invoke($service, $payload, [
            ['key' => 'n_01'],
            ['key' => 'n_02'],
        ]);

        $this->assertSame(['n_01'], data_get($normalized, 'covered_need_keys'));
        $this->assertSame(['n_02'], data_get($normalized, 'remaining_need_keys'));
        $this->assertFalse(data_get($normalized, 'complete'));
        $this->assertFalse(data_get($normalized, 'enough_for_people'));
        $this->assertNull(data_get($normalized, 'revisit_need_key'));
        $this->assertNull(data_get($normalized, 'revisit_query'));
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
        $this->assertStringContainsString('декомпозуй її на конкретні товарні сімʼї', $preparationPrompt);
        $this->assertStringContainsString('не перетворюй бажану властивість на жорстку', $preparationPrompt);
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
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не змінюй candidate_matches_required_product з false на true',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не вимагай, щоб назва товару дослівно повторювала майбутній рецепт',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не відкидай придатне сире мʼясо лише тому',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'фізична форма мусить бути сумісною зі способом приготування',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'назва або атрибути прямо й позитивно заявляють потрібну безглютенову',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Сам текст current_need не є доказом властивостей candidate',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Назва порції або нарізки, яку також уживають як назву іншої страви',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не називай такий кандидат готовим без позитивної ознаки обробки',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'назва одного звичного способу не робить їх несумісними з іншим',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'точною категорією свіжих фруктів чи овочів є позитивним доказом',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Не поширюй особисте обмеження одного учасника механічно на кожен спільний товар',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не може самостійно покривати велику вагову потребу загального продукту',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Не обирай SKU поза явним діапазоном',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'ширше модельне міркування про роль потреби',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Модель, а не PHP, має визначити її',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Товар, який каталог прямо називає безалкогольним',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'selected_product_id обовʼязково має бути точним ID',
        ));
    }

    public function test_similarity_adjudication_is_a_bounded_final_candidate_review(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
        ]);
        Http::fake([
            '*' => Http::response($this->openAiDecisionResponse([
                'action' => 'skip',
                'reason' => 'No compatible candidate remains.',
            ])),
        ]);

        app(CartProductDecisionService::class)->decide([
            'current_need' => [
                'key' => 'n_01',
                'similarity_adjudication' => true,
                'attempts' => [],
            ],
            'all_needs' => [['key' => 'n_01']],
            'candidates' => [],
        ]);

        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'ФІНАЛЬНА ПЕРЕВІРКА ЗА ПОДІБНІСТЮ',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не повертай retry',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'можна select найближчий',
        ));
    }

    public function test_invalid_inspection_decision_gets_one_bounded_model_repair(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
        ]);
        $candidate = [
            'product_id' => 'sauce-1',
            'name' => 'Томатний соус',
        ];
        Http::fakeSequence()
            ->push($this->openAiDecisionResponse([
                'action' => 'inspect',
                'selected_product_id' => null,
                'reason' => 'Потрібно перевірити склад кандидата.',
            ]))
            ->push($this->openAiDecisionResponse([
                'action' => 'inspect',
                'selected_product_id' => 'sauce-1',
                'reason' => 'Перевірити склад точного кандидата.',
            ]));

        $decision = app(CartProductDecisionService::class)->decide([
            'current_need' => ['key' => 'n_01', 'attempts' => []],
            'all_needs' => [['key' => 'n_01']],
            'candidates' => [$candidate],
        ]);

        $this->assertSame('inspect', $decision->action);
        $this->assertSame('sauce-1', $decision->selectedProductId);
        Http::assertSentCount(2);
        $repairPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'input.0.content.0.text');
        $this->assertStringContainsString('одна і єдина спроба ремонту', $repairPrompt);
        $this->assertStringContainsString('Agent selected no catalog product.', $repairPrompt);
        $this->assertStringContainsString('sauce-1', $repairPrompt);
    }

    public function test_final_audit_scopes_personal_restrictions_to_the_intended_consumer(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
        ]);
        Http::fake([
            '*' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'complete' => true,
                            'covered_need_keys' => ['n_01'],
                            'remaining_need_keys' => [],
                            'enough_for_people' => true,
                            'warnings' => ['Учаснику з обмеженням не подавати цей товар.'],
                            'revisit_need_key' => null,
                            'revisit_query' => null,
                            'question' => null,
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ]),
        ]);

        app(CartProductDecisionService::class)->audit([
            'needs' => [['key' => 'n_01']],
        ]);

        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Не поширюй особисте обмеження одного учасника механічно на весь кошик',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'цій людині товар не можна споживати',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'staged-товар поза ним не вважай покриттям',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Слово «приблизно», практична оцінка або орієнтовний розмір не є точним діапазоном',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'не залишай прийняті staged optional-позиції у remaining_need_keys',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'warning каже, що позиція покрита чи достатня',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Каталожне маркування «безалкогольний» приймай як безалкогольний статус',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Вичерпання пошуків не робить несумісну фізичну форму сумісною',
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

    /** @param array<string, mixed> $overrides */
    private function openAiDecisionResponse(array $overrides): array
    {
        $decision = [
            'action' => 'skip',
            'selected_product_id' => null,
            'query' => null,
            'quantity' => null,
            'reason' => 'No suitable candidate.',
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
            'allow_catalog_fallback' => false,
            'candidate_matches_required_product' => true,
            'safety_evidence' => 'not_required',
            'is_replacement' => false,
            ...$overrides,
        ];

        return [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($decision, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ];
    }
}
