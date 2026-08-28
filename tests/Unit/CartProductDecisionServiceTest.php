<?php

namespace Tests\Unit;

use App\Data\CartAgentPreparationData;
use App\Services\CartProductDecisionService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;
use UnexpectedValueException;

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

        $this->assertContains('need_key', $schema['required']);
        $this->assertContains('candidate_matches_required_product', $schema['required']);
        $this->assertContains('is_replacement', $schema['required']);
        $this->assertSame(['type' => 'string'], data_get($schema, 'properties.need_key'));
        $this->assertSame(
            ['type' => 'boolean'],
            data_get($schema, 'properties.candidate_matches_required_product'),
        );
        $this->assertSame(['type' => 'boolean'], data_get($schema, 'properties.is_replacement'));
    }

    public function test_product_first_preparation_is_shared_by_both_harness_modes(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $promptMethod = $reflection->getMethod('preparationPrompt');
        $repairPromptMethod = $reflection->getMethod('preparationRepairPrompt');

        $productFirstPrompt = $promptMethod->invoke($service, [], ['items' => []]);
        $productFirstRepairPrompt = $repairPromptMethod->invoke($service, [], [], [], []);

        $this->assertStringContainsString('конкретну куповану харчову ідентичність', $productFirstPrompt);
        $this->assertStringContainsString('ДО пошуку використай кулінарні знання', $productFirstPrompt);
        $this->assertStringContainsString('розділяй discovery та suitability', $productFirstPrompt);
        $this->assertStringContainsString('використовуються тільки після отримання кандидатів', $productFirstPrompt);
        $this->assertStringContainsString('не за фіксованою таблицею відповідностей', $productFirstPrompt);
        $this->assertStringContainsString('серед перших трьох search_queries', $productFirstPrompt);
        $this->assertStringContainsString('а не витрачай список на відмінки', $productFirstPrompt);
        $this->assertStringNotContainsString('name зберігає повну людську назву потреби', $productFirstPrompt);
        $this->assertStringContainsString('розділяй discovery та suitability', $productFirstRepairPrompt);
        $this->assertStringContainsString('не PHP-таблиці відповідностей', $productFirstRepairPrompt);
        $this->assertStringContainsString('серед перших трьох search_queries', $productFirstRepairPrompt);
    }

    public function test_v1_uses_the_llm_food_identity_as_the_first_catalog_query(): void
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
                            'needs' => [[
                                'key' => 'model-key',
                                'source_index' => 0,
                                'name' => 'Ошийок свинячий охолоджений',
                                'category' => 'food',
                                'quantity' => 2.25,
                                'unit' => 'кг',
                                'note' => 'Для приготування свинячого шашлику.',
                                'search_queries' => [
                                    'свинячий ошийок',
                                    'ошийок свинина',
                                ],
                            ]],
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]],
                ]],
            ]),
        ]);

        $preparation = app(CartProductDecisionService::class)->prepare([], [
            'items' => [[
                'name' => 'Свинина',
                'category' => 'food',
                'quantity' => 2.25,
                'unit' => 'кг',
                'note' => 'Для шашлику.',
            ]],
        ]);

        $this->assertSame('Ошийок свинячий охолоджений', data_get($preparation->needs, '0.name'));
        $this->assertSame('Ошийок свинячий охолоджений', data_get($preparation->needs, '0.search_query'));
        $this->assertSame('Для приготування свинячого шашлику.', data_get($preparation->needs, '0.note'));
        $this->assertTrue(data_get($preparation->needs, '0.retailer_identity_prepared'));
        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'instructions'),
            'ДО пошуку використай кулінарні знання',
        ) && str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            'Для шашлику.',
        ));
    }

    public function test_zero_result_search_intent_splits_product_name_and_purpose_in_one_llm_request(): void
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
                            'product_name' => 'перець',
                            'purpose' => 'для гриля',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        $intent = app(CartProductDecisionService::class)->diversifySearch([
            'name' => 'перець для гриля',
            'note' => 'untrusted-user-data-sentinel',
            'attempts' => [['query' => 'перець для гриля', 'raw_total_found' => 0]],
        ]);

        $this->assertSame('перець', $intent->productName);
        $this->assertSame('для гриля', $intent->purpose);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'text.format.name') === 'cart_agent_search_intent'
                && data_get($payload, 'text.format.schema.required') === ['product_name', 'purpose']
                && str_contains(
                    (string) data_get($payload, 'instructions'),
                    'повернув рівно нуль товарів',
                )
                && str_contains(
                    (string) data_get($payload, 'input.0.content.0.text'),
                    'untrusted-user-data-sentinel',
                )
                && ! str_contains(
                    (string) data_get($payload, 'instructions'),
                    'untrusted-user-data-sentinel',
                );
        });
    }

    public function test_ollama_receives_system_instructions_and_separate_user_data(): void
    {
        config([
            'services.ai.provider' => 'ollama',
            'services.ai.providers.ollama.base_url' => 'https://ollama.test/v1',
            'services.ai.providers.ollama.api_key' => 'test-key',
            'services.ai.model' => 'test-model',
            'services.ai.lexical_model' => 'gemma4:31b',
        ]);
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => "```json\n".json_encode([
                        'product_name' => 'перець',
                        'purpose' => 'для гриля',
                    ], JSON_THROW_ON_ERROR)."\n```"],
                ]],
            ]),
        ]);

        $intent = app(CartProductDecisionService::class)->diversifySearch([
            'name' => 'untrusted-user-data-sentinel',
        ]);

        $this->assertSame('перець', $intent->productName);
        $this->assertSame('для гриля', $intent->purpose);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $systemText = (string) data_get($payload, 'messages.0.content.0.text');
            $userText = (string) data_get($payload, 'messages.1.content.0.text');

            return data_get($payload, 'messages.0.role') === 'system'
                && data_get($payload, 'messages.1.role') === 'user'
                && data_get($payload, 'model') === 'gemma4:31b'
                && str_contains($systemText, 'Прямий пошук Сільпо')
                && str_contains($systemText, 'Поверни лише один валідний JSON object')
                && str_contains($systemText, '"required":["product_name","purpose"]')
                && ! str_contains($systemText, 'untrusted-user-data-sentinel')
                && str_contains($userText, 'untrusted-user-data-sentinel')
                && ! str_contains($userText, 'Поверни лише');
        });
    }

    public function test_openai_ignores_the_ollama_lexical_model_split(): void
    {
        config([
            'services.ai.provider' => 'openai',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'openai-primary-model',
            'services.ai.lexical_model' => 'gemma4:31b',
        ]);
        Http::fake([
            '*' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode([
                            'product_name' => 'перець',
                            'purpose' => 'для гриля',
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        app(CartProductDecisionService::class)->diversifySearch([
            'name' => 'перець для гриля',
        ]);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'model') === 'openai-primary-model');
    }

    public function test_ollama_uses_the_primary_model_outside_lexical_cart_requests(): void
    {
        config([
            'services.ai.provider' => 'ollama',
            'services.ai.model' => 'qwen3.5:397b',
            'services.ai.lexical_model' => 'gemma4:31b',
        ]);
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = app(CartProductDecisionService::class);
        $modelForRequest = $reflection->getMethod('modelForRequest');

        $this->assertSame('qwen3.5:397b', $modelForRequest->invoke($service, 'cart_agent_preparation'));
        $this->assertSame('qwen3.5:397b', $modelForRequest->invoke($service, 'cart_agent_audit'));
        $this->assertSame('gemma4:31b', $modelForRequest->invoke($service, 'cart_agent_search_intent'));
        $this->assertSame('gemma4:31b', $modelForRequest->invoke($service, 'cart_agent_decision'));
        $this->assertSame('gemma4:31b', $modelForRequest->invoke($service, 'cart_agent_decision_repair'));
    }

    public function test_both_providers_get_the_short_catalog_query_rule_while_ollama_keeps_extra_language_guidance(): void
    {
        $service = app(CartProductDecisionService::class);
        $reflection = new ReflectionClass($service);
        $preparationPrompt = $reflection->getMethod('preparationPrompt');
        $decisionGuidance = $reflection->getMethod('ollamaDecisionGuidance');

        config(['services.ai.provider' => 'ollama']);
        $ollamaPrompt = $preparationPrompt->invoke($service, [], ['items' => []]);

        $this->assertStringContainsString('найкоротшу природну каталожну назву', $ollamaPrompt);
        $this->assertStringContainsString('іншого граматичного числа', $ollamaPrompt);
        $this->assertStringContainsString('іншу абетку, транслітерацію', $decisionGuidance->invoke($service));
        $this->assertStringContainsString('самостійно перечитай product_constraints', $decisionGuidance->invoke($service));
        $this->assertStringContainsString('safety_evidence=unverified не виконує потребу', $decisionGuidance->invoke($service));
        $this->assertStringContainsString('не узагальнюй результат inspect одного бренду', $decisionGuidance->invoke($service));

        config(['services.ai.provider' => 'openai']);
        $openAiPrompt = $preparationPrompt->invoke($service, [], ['items' => []]);

        $this->assertStringNotContainsString('ДОДАТКОВА САМОПЕРЕВІРКА ДЛЯ OLLAMA', $openAiPrompt);
        $this->assertStringContainsString('найкоротшу природну каталожну назву', $openAiPrompt);
        $this->assertSame('', $decisionGuidance->invoke($service));
    }

    public function test_ollama_classifies_positive_evidence_in_a_separate_lexical_request(): void
    {
        config([
            'services.ai.provider' => 'ollama',
            'services.ai.providers.ollama.base_url' => 'https://ollama.test/v1',
            'services.ai.providers.ollama.api_key' => 'test-key',
            'services.ai.model' => 'qwen3.5:397b',
            'services.ai.lexical_model' => 'gemma4:31b',
        ]);
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'needs' => [
                            ['key' => 'n_01', 'requires_positive_evidence' => true],
                            ['key' => 'n_02', 'requires_positive_evidence' => false],
                        ],
                    ], JSON_THROW_ON_ERROR)],
                ]],
            ]),
        ]);
        $preparation = new CartAgentPreparationData([
            ['key' => 'n_01', 'source_index' => 0, 'name' => 'Перший товар', 'note' => ''],
            ['key' => 'n_02', 'source_index' => 1, 'name' => 'Другий товар', 'note' => ''],
        ]);
        $service = app(CartProductDecisionService::class);
        $method = (new ReflectionClass($service))->getMethod('withOllamaEvidenceRequirements');

        $classified = $method->invoke($service, $preparation, [], ['items' => []], null);

        $this->assertTrue(data_get($classified->needs, '0.requires_positive_evidence'));
        $this->assertFalse(data_get($classified->needs, '1.requires_positive_evidence'));
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'model') === 'gemma4:31b'
            && str_contains(
                (string) data_get($request->data(), 'messages.0.content.0.text'),
                'cart_agent_evidence_requirements',
            ));
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
        $this->assertStringContainsString(
            'конкретну куповану харчову ідентичність',
            (string) data_get(Http::recorded()[0][0]->data(), 'instructions'),
        );
        Http::assertSent(fn ($request): bool => str_contains(
            (string) data_get($request->data(), 'input.0.content.0.text'),
            '"source_index": 6',
        ));
        $secondPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'input.0.content.0.text');
        $this->assertStringContainsString('"active_source_indexes": [', $secondPrompt);
        $this->assertStringContainsString('"name": "яблука"', $secondPrompt);
        $this->assertStringContainsString('"name": "серветки"', $secondPrompt);
        $this->assertStringContainsString(
            'повертай needs ЛИШЕ для active_source_indexes',
            (string) data_get(Http::recorded()[1][0]->data(), 'instructions'),
        );
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
            'need_key' => 'n_01',
            'audit' => [
                'covered_need_keys' => ['n_01', 'invented'],
                'remaining_need_keys' => ['n_01'],
                'revisit_need_key' => 'invented',
                'revisit_query' => 'wrong query',
            ],
        ];

        $normalized = $reflection->getMethod('normalizeDecisionPayload')->invoke($service, $payload, [
            'current_need' => ['key' => 'n_01'],
            'all_needs' => [['key' => 'n_01'], ['key' => 'n_02']],
        ]);

        $this->assertSame(['n_01'], data_get($normalized, 'audit.covered_need_keys'));
        $this->assertSame(['n_02'], data_get($normalized, 'audit.remaining_need_keys'));
        $this->assertNull(data_get($normalized, 'audit.revisit_need_key'));
        $this->assertNull(data_get($normalized, 'audit.revisit_query'));
    }

    public function test_decision_payload_rejects_a_different_need_key(): void
    {
        $reflection = new ReflectionClass(CartProductDecisionService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Cart decision targeted a different need.');

        $reflection->getMethod('normalizeDecisionPayload')->invoke($service, [
            'need_key' => 'n_02',
        ], [
            'current_need' => ['key' => 'n_01'],
            'all_needs' => [['key' => 'n_01'], ['key' => 'n_02']],
        ]);
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
        Http::assertSent(fn ($request): bool => collect([
            'retailer-specific-query-marker',
            'retailer-specific-safety-marker',
            'рольова заміна',
            'паковання треба перевірити людині',
            'не змінюй candidate_matches_required_product з false на true',
            'не вимагай, щоб назва товару дослівно повторювала майбутній рецепт',
            'не відкидай придатне сире мʼясо лише тому',
            'фізична форма мусить бути сумісною зі способом приготування',
            'назва або атрибути прямо й позитивно заявляють потрібну безглютенову',
            'Сам текст current_need не є доказом властивостей candidate',
            'Назва порції або нарізки, яку також уживають як назву іншої страви',
            'не називай такий кандидат готовим без позитивної ознаки обробки',
            'назва одного звичного способу не робить їх несумісними з іншим',
            'точною категорією свіжих фруктів чи овочів є позитивним доказом',
            'Не поширюй особисте обмеження одного учасника механічно на кожен спільний товар',
            'не може самостійно покривати велику вагову потребу загального продукту',
            'Не обирай SKU поза явним діапазоном',
            'ширше модельне міркування про роль потреби',
            'Модель, а не PHP, має визначити її',
            'Товар, який каталог прямо називає безалкогольним',
            'selected_product_id обовʼязково має бути точним ID',
            'Оціни весь масив candidates одним рішенням',
            'очевидно однокомпонентного сирого мʼяса',
        ])->every(fn (string $needle): bool => str_contains(
            (string) data_get($request->data(), 'instructions'),
            $needle,
        )));
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
            (string) data_get($request->data(), 'instructions'),
            'ФІНАЛЬНА ПЕРЕВІРКА ЗА ПОДІБНІСТЮ',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'не повертай retry',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
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
        $repairRequest = Http::recorded()[1][0]->data();
        $this->assertStringContainsString(
            'одна і єдина спроба ремонту',
            (string) data_get($repairRequest, 'instructions'),
        );
        $repairData = (string) data_get($repairRequest, 'input.0.content.0.text');
        $this->assertStringContainsString('Agent selected no catalog product.', $repairData);
        $this->assertStringContainsString('sauce-1', $repairData);
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
            (string) data_get($request->data(), 'instructions'),
            'Не поширюй особисте обмеження одного учасника механічно на весь кошик',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'цій людині товар не можна споживати',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'staged-товар поза ним не вважай покриттям',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'Слово «приблизно», практична оцінка або орієнтовний розмір не є точним діапазоном',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'не залишай прийняті staged optional-позиції у remaining_need_keys',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'warning каже, що позиція покрита чи достатня',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'safety_evidence=not_required',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
            'Каталожне маркування «безалкогольний» приймай як безалкогольний статус',
        ) && str_contains(
            (string) data_get($request->data(), 'instructions'),
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
            'need_key' => 'n_01',
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
