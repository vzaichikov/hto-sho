<?php

namespace Tests\Feature;

use App\EventAnalysisStage;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\ImageClassification;
use App\Jobs\BuildEventShoppingPlanJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use App\Models\User;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use DateTimeInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventContextSummaryJobTest extends TestCase
{
    use DatabaseTransactions;

    public function test_summary_execution_budget_covers_a_bounded_repair(): void
    {
        $job = new SummarizeEventContextJob(1, (string) Str::ulid());

        $this->assertSame(380, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertGreaterThan(2 * config('services.ai.context_request_timeout'), $job->timeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertSame(0, $job->tries);
        $this->assertSame(7200, $job->uniqueFor);
        $this->assertGreaterThan(now()->addMinutes(119), $job->retryUntil());
        $this->assertLessThan(now()->addMinutes(121), $job->retryUntil());
    }

    public function test_waiting_for_unfinished_images_releases_within_the_time_budget(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        EventSource::factory()->for($event)->create([
            'type' => EventSourceType::Image,
            'status' => EventSourceStatus::Processing,
            'text' => null,
            'processed_at' => null,
        ]);
        $job = (new SummarizeEventContextJob($event->id, $taskId))
            ->withFakeQueueInteractions();

        $job->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $job->assertReleased(delay: 5);
        $this->assertSame(EventAnalysisStage::WaitingForImages, $event->refresh()->analysis_stage);
        Http::assertNothingSent();
    }

    public function test_terminal_failure_hides_the_internal_exception_from_the_customer(): void
    {
        [$event, $taskId] = $this->activeEvent(1);

        (new SummarizeEventContextJob($event->id, $taskId))->failed(
            new \RuntimeException('App\\Jobs\\SummarizeEventContextJob has been attempted too many times.'),
        );

        $event->refresh();
        $this->assertSame(EventAnalysisStage::Failed, $event->analysis_stage);
        $this->assertSame(
            'Гусь не зміг зібрати контекст цього разу. Усі матеріали збережені — спробуйте запустити аналіз ще раз.',
            $event->analysis_error,
        );
        $this->assertStringNotContainsString('App\\Jobs', $event->analysis_error);
    }

    public function test_complete_named_roster_cannot_reopen_a_participant_names_question(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $event->update([
            'description' => 'Мангал для восьми названих друзів.',
            'people_count' => 8,
        ]);
        $participants = collect(['Роман', 'Іра', 'Оля', 'Маша', 'Леся', 'Тарас', 'Богдан', 'Катя'])
            ->map(fn (string $name): array => [
                'name' => $name,
                'status' => 'confirmed',
                'preferences' => [],
                'restrictions' => [],
                'allergies' => [],
                'brings' => [],
                'source_ids' => [],
            ])
            ->all();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'summary' => 'Усі восьмеро названі.',
                'participants' => $participants,
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [[
                    'question_key' => '__new__',
                    'question' => 'Хто саме входить до складу восьми учасників?',
                    'impact' => 'Потрібні імена всіх гостей.',
                    'blocking' => false,
                    'options' => [[
                        'label' => 'Уточнити імена',
                        'description' => '',
                        'recommended' => true,
                    ], [
                        'label' => 'Лишити без імен',
                        'description' => '',
                        'recommended' => false,
                    ], [
                        'label' => 'Повернутися пізніше',
                        'description' => '',
                        'recommended' => false,
                    ]],
                    'source_ids' => [],
                ]],
                'source_ids' => [],
            ])),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertCount(8, $event->state['participants']);
        $this->assertSame([], $event->state['unresolved_questions']);
    }

    public function test_newer_tentative_text_removes_only_the_named_contributions_from_brings(): void
    {
        [$event, $taskId] = $this->activeEvent(2);
        $older = EventSource::factory()->for($event)->create([
            'text' => '23 серпня, 09:13, Тарас: Я беру вугілля, розпал, мангал та шампури.',
            'created_at' => now()->subMinute(),
        ]);
        $newer = EventSource::factory()->for($event)->create([
            'text' => '23 серпня, 09:16, Роман: Тарас ніби бере вугілля й розпал. Це ще не фінальні обіцянки, не викреслюйте з закупів.',
            'created_at' => now(),
        ]);
        $payload = $this->summaryPayload([$older->id, $newer->id]);
        $payload['participants'] = [[
            'name' => 'Тарас',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => ['вугілля', 'розпал', 'мангал', 'шампури'],
            'source_ids' => [$older->id, $newer->id],
        ]];
        $payload['restrictions'] = [];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(['мангал', 'шампури'], $event->state['participants'][0]['brings']);
    }

    public function test_future_promise_to_confirm_is_not_a_confirmed_contribution(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '24 серпня, 09:32, Леся: Торт теж maybe на мені, підтверджу.',
        ]);
        $payload = $this->summaryPayload([$source->id]);
        $payload['participants'] = [[
            'name' => 'Леся',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => ['торт'],
            'source_ids' => [$source->id],
        ]];
        $payload['restrictions'] = [];
        $payload['summary'] = 'Леся остаточно підтвердила, що бере торт. Інші деталі вечері ще уточнюються.';
        $payload['agreements'] = [[
            'summary' => 'Леся остаточно підтвердила торт.',
            'source_ids' => [$source->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame([], $event->state['participants'][0]['brings']);
        $this->assertSame([], $event->state['agreements']);
        $this->assertStringNotContainsString('торт', Str::lower($event->state['summary']));
        $this->assertTrue(collect($event->state['warnings'])->contains(
            fn (array $warning): bool => str_contains(Str::lower($warning['message']), 'умовн'),
        ));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request['instructions'],
            'Майбутнє «підтверджу» означає, що підтвердження ще буде',
        ));
    }

    public function test_confirmed_peanut_safe_hummus_is_not_tentative_and_closes_the_old_allergy_question(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '25 серпня, 18:35, Оля бере хумус без арахісу й без маркування «може містити арахіс». Маша має сильну алергію саме на арахіс.',
        ]);
        $payload = $this->summaryPayload([$source->id]);
        $payload['participants'] = [[
            'name' => 'Оля',
            'status' => 'confirmed',
            'preferences' => ['вегетаріанка'],
            'restrictions' => ['без арахісу'],
            'allergies' => ['арахіс'],
            'brings' => ['800 г хумусу'],
            'source_ids' => [$source->id],
        ], [
            'name' => 'Маша',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => ['без арахісу'],
            'allergies' => ['арахіс'],
            'brings' => [],
            'source_ids' => [$source->id],
        ]];
        $payload['restrictions'] = [[
            'participant' => 'Маша',
            'restriction' => 'сильна алергія на арахіс',
            'severity' => 'allergy',
            'source_ids' => [$source->id],
        ]];
        $payload['unresolved_questions'] = [[
            'question_key' => '__new__',
            'question' => 'Чи є у Маші алергія на горіхи або арахіс?',
            'impact' => 'Впливає на безпечний склад покупок.',
            'blocking' => false,
            'options' => [[
                'label' => 'Уточнити в Маші',
                'description' => '',
                'recommended' => true,
            ], [
                'label' => 'Виключити арахіс',
                'description' => '',
                'recommended' => false,
            ], [
                'label' => 'Повернутися пізніше',
                'description' => '',
                'recommended' => false,
            ]],
            'source_ids' => [$source->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(['800 г хумусу'], $event->state['participants'][0]['brings']);
        $this->assertSame([], $event->state['participants'][0]['restrictions']);
        $this->assertSame([], $event->state['participants'][0]['allergies']);
        $this->assertSame([], $event->state['unresolved_questions']);
    }

    public function test_explicit_pork_preference_and_final_charcoal_takeover_survive_model_omissions(): void
    {
        [$event, $taskId] = $this->activeEvent(2);
        $preference = EventSource::factory()->for($event)->create([
            'text' => '23 серпня, 09:05, Роман: я свинину люблю на шашлик',
        ]);
        $takeover = EventSource::factory()->for($event)->create([
            'text' => '25 серпня, 18:42, Богдан: Вугілля — дві пачки по 2,5 кг — і розпал беру точно я.',
        ]);
        $payload = $this->summaryPayload([$preference->id, $takeover->id]);
        $payload['participants'] = [[
            'name' => 'Роман',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => [],
            'source_ids' => [$preference->id],
        ], [
            'name' => 'Богдан',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => [],
            'source_ids' => [$takeover->id],
        ]];
        $payload['restrictions'] = [];
        $payload['warnings'] = [[
            'message' => 'Богданове підхоплення вугілля ще не підтверджене.',
            'source_ids' => [$takeover->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(['свинина на шашлик'], $event->state['participants'][0]['preferences']);
        $this->assertSame(['2 пачки вугілля по 2,5 кг', 'розпал'], $event->state['participants'][1]['brings']);
        $this->assertSame([], $event->state['warnings']);
    }

    public function test_a_negative_clause_for_whiskey_does_not_cancel_charcoal_and_starter(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '26 серпня, 18:20, Роман: Фінальні внески: Богдан бере 2 пачки вугілля й розпал, але віскі не бере.',
        ]);
        $payload = $this->summaryPayload([$source->id]);
        $payload['participants'] = [[
            'name' => 'Богдан',
            'status' => 'confirmed',
            'preferences' => ['віскі'],
            'restrictions' => [],
            'allergies' => [],
            'brings' => ['2 пачки вугілля', 'розпал'],
            'source_ids' => [$source->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(['2 пачки вугілля', 'розпал'], $event->state['participants'][0]['brings']);
    }

    public function test_contribution_refusal_gets_one_repair_instead_of_erasing_consumption_preference(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '26 серпня, 18:20, Роман: Фінальні внески: Богдан бере вугілля, але віскі не бере.',
        ]);
        $draft = $this->summaryPayload([$source->id]);
        $draft['participants'] = [[
            'name' => 'Богдан',
            'status' => 'confirmed',
            'preferences' => ['пиво'],
            'restrictions' => ['Не бере віскі'],
            'allergies' => [],
            'brings' => ['вугілля'],
            'source_ids' => [$source->id],
        ]];
        $draft['restrictions'] = [[
            'participant' => 'Богдан',
            'restriction' => 'Не бере віскі',
            'severity' => 'preference',
            'source_ids' => [$source->id],
        ]];
        $repaired = $draft;
        $repaired['participants'][0]['preferences'] = ['пиво', 'віскі'];
        $repaired['participants'][0]['restrictions'] = [];
        $repaired['restrictions'] = [];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse($draft))
                ->push($this->openAiResponse($repaired)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(['пиво', 'віскі'], $event->state['participants'][0]['preferences']);
        $this->assertSame([], $event->state['participants'][0]['restrictions']);
        $this->assertSame([], $event->state['restrictions']);
        Http::assertSentCount(2);
        $repairPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'instructions');
        $this->assertStringContainsString('не перетворена на відмову їсти чи пити', $repairPrompt);
    }

    public function test_one_bounded_repair_restores_an_omitted_tentative_contribution_need(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '24 серпня, 09:31, Роман: Леся, якщо встигне, торт. Це ще не фінальна обіцянка.',
        ]);
        $draft = $this->summaryPayload([$source->id]);
        $draft['participants'] = [[
            'name' => 'Леся',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => [],
            'source_ids' => [$source->id],
        ]];
        $repaired = $draft;
        $repaired['shopping_requirements'] = [[
            'name' => 'торт',
            'quantity' => null,
            'unit' => null,
            'constraints' => ['умовний внесок Лесі ще не підтверджений'],
            'source_ids' => [$source->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse($draft))
                ->push($this->openAiResponse($repaired)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame('торт', $event->state['shopping_requirements'][0]['name']);
        $this->assertSame([], $event->state['participants'][0]['brings']);
        Http::assertSentCount(2);
        $this->assertTrue(Http::recorded()->contains(fn (array $record): bool => str_contains(
            $record[0]['instructions'],
            'Ти один раз перевіряєш повноту',
        )));
    }

    public function test_repair_reuses_the_server_key_assigned_to_a_new_draft_question(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => '24 серпня, 09:31, Роман: Леся, якщо встигне, торт. Це ще не фінальна обіцянка.',
        ]);
        $draft = $this->summaryPayload([$source->id]);
        $draft['participants'] = [[
            'name' => 'Леся',
            'status' => 'confirmed',
            'preferences' => [],
            'restrictions' => [],
            'allergies' => [],
            'brings' => [],
            'source_ids' => [$source->id],
        ]];
        $draft['restrictions'] = [];
        $draft['unresolved_questions'] = [[
            'question_key' => '__new__',
            'question' => 'Чи підтвердила Леся торт?',
            'impact' => 'Відповідь визначає, чи купувати торт.',
            'blocking' => false,
            'options' => [[
                'label' => 'Купити про запас',
                'description' => '',
                'recommended' => true,
            ], [
                'label' => 'Уточнити в Лесі',
                'description' => '',
                'recommended' => false,
            ], [
                'label' => 'Не купувати',
                'description' => '',
                'recommended' => false,
            ]],
            'source_ids' => [$source->id],
        ]];
        $requestCount = 0;
        $assignedQuestionKey = null;
        Http::fake(function (Request $request) use (&$requestCount, &$assignedQuestionKey, $draft, $source) {
            $requestCount++;

            if ($requestCount === 1) {
                return Http::response($this->openAiResponse($draft));
            }

            $userData = json_decode(
                (string) data_get($request->data(), 'input.0.content.0.text'),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            $repaired = $userData['draft_context'];
            $assignedQuestionKey = $repaired['unresolved_questions'][0]['key'];
            $repaired['unresolved_questions'][0]['question_key'] = $assignedQuestionKey;
            unset($repaired['unresolved_questions'][0]['key']);
            $repaired['shopping_requirements'] = [[
                'name' => 'торт',
                'quantity' => null,
                'unit' => null,
                'constraints' => ['умовний внесок Лесі ще не підтверджений'],
                'source_ids' => [$source->id],
            ]];

            return Http::response($this->openAiResponse($repaired));
        });

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame(2, $requestCount);
        $this->assertIsString($assignedQuestionKey);
        $this->assertStringStartsWith('q_', $assignedQuestionKey);
        $this->assertSame($assignedQuestionKey, $event->state['unresolved_questions'][0]['key']);
        $this->assertSame(EventAnalysisStage::Completed, $event->analysis_stage);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.model' => 'gpt-5.4-mini',
            'services.ai.api_key' => 'test-key',
        ]);
    }

    public function test_full_job_preserves_chronology_provenance_and_commits_current_revision(): void
    {
        [$event, $taskId] = $this->activeEvent(2);
        $older = EventSource::factory()->for($event)->create([
            'text' => 'Спочатку: зустріч у суботу.',
            'content_hash' => hash('sha256', 'older'),
            'created_at' => now()->subMinutes(2),
        ]);
        $newer = EventSource::factory()->for($event)->create([
            'text' => 'Уточнення: перенесли на неділю.',
            'content_hash' => hash('sha256', 'newer'),
            'created_at' => now()->subMinute(),
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse(
                $this->summaryPayload([$older->id, $newer->id]),
            )),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(EventStatus::Ready, $event->status);
        $this->assertSame(EventAnalysisStage::Completed, $event->analysis_stage);
        $this->assertSame(1, $event->state_version);
        $this->assertSame(2, $event->state_evidence_version);
        $this->assertSame([$older->id, $newer->id], $event->state['source_ids']);
        $this->assertDatabaseHas('event_context_versions', [
            'event_id' => $event->id,
            'state_version' => 1,
            'evidence_version' => 2,
        ]);
        Queue::assertPushed(BuildEventShoppingPlanJob::class, fn (BuildEventShoppingPlanJob $job): bool => $job->eventId === $event->id && $job->stateVersion === 1);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));
        $this->assertSame(1, $event->contextVersions()->count());
        Http::assertSent(function (Request $request): bool {
            $userJson = $request['input'][0]['content'][0]['text'];

            return is_string($request['instructions'])
                && $request['input'][0]['role'] === 'user'
                && mb_strpos($userJson, 'Спочатку: зустріч у суботу.')
                    < mb_strpos($userJson, 'Уточнення: перенесли на неділю.');
        });
    }

    public function test_ollama_summary_also_receives_system_rules_and_separate_user_json(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $source = EventSource::factory()->for($event)->create([
            'text' => 'Оля бере овочі.',
        ]);
        config()->set('services.ai.provider', 'ollama');
        Http::fake([
            'https://ollama.com/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode(
                            $this->summaryPayload([$source->id]),
                            JSON_UNESCAPED_UNICODE,
                        ),
                    ],
                ]],
            ]),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $this->assertSame(EventAnalysisStage::Completed, $event->refresh()->analysis_stage);
        Http::assertSent(function (Request $request) use ($source): bool {
            $systemPrompt = (string) data_get($request->data(), 'messages.0.content.0.text');
            $userJson = (string) data_get($request->data(), 'messages.1.content.0.text');

            return data_get($request->data(), 'messages.0.role') === 'system'
                && data_get($request->data(), 'messages.1.role') === 'user'
                && str_contains($systemPrompt, 'ОБОВʼЯЗКОВА JSON SCHEMA (event_context)')
                && str_contains($systemPrompt, '"required":["summary","participants","restrictions","agreements","shopping_requirements","warnings","unresolved_questions","source_ids"]')
                && str_contains($userJson, '"source_batches"')
                && str_contains($userJson, '"source_id": '.$source->id)
                && data_get($request->data(), 'response_format.type') === 'json_object';
        });
    }

    public function test_full_job_sends_reverse_uploads_as_timestamped_batches_and_resolved_updates_are_not_warnings(): void
    {
        [$event, $taskId] = $this->activeEvent(4);
        $firstBatch = (string) Str::ulid();
        $secondBatch = (string) Str::ulid();
        $thirdBatch = (string) Str::ulid();
        $uploadedAt = now()->subDays(2);

        $laterInFirstBatch = $this->imageSource(
            event: $event,
            batch: $firstBatch,
            position: 0,
            ocr: "Саша\nВугілля вже не беру — купіть 2 пачки.\n12:50",
            messageTimeline: [$this->message('Саша', 'Вугілля вже не беру — купіть 2 пачки.', '22.08.2026', '12:50')],
            createdAt: $uploadedAt,
        );
        $olderInFirstBatch = $this->imageSource(
            event: $event,
            batch: $firstBatch,
            position: 1,
            ocr: "Саша\nЯ беру вугілля і розпал.\n11:50",
            messageTimeline: [$this->message('Саша', 'Я беру вугілля і розпал.', '22.08.2026', '11:50')],
            createdAt: $uploadedAt->copy()->addSecond(),
        );
        $nextDay = $this->imageSource(
            event: $event,
            batch: $secondBatch,
            position: 0,
            ocr: "Іра\nПереносимо на 15:00.\n10:30",
            messageTimeline: [$this->message('Іра', 'Переносимо на 15:00.', '23.08.2026', '10:30')],
            createdAt: $uploadedAt->copy()->addDay(),
        );
        $oldScreenshotUploadedLast = $this->imageSource(
            event: $event,
            batch: $thirdBatch,
            position: 0,
            ocr: "Іра\nЗустрічаємось о 14:00.\n18.08.2026 09:00",
            messageTimeline: null,
            createdAt: $uploadedAt->copy()->addDays(2),
        );
        $sourceIds = [
            $laterInFirstBatch->id,
            $olderInFirstBatch->id,
            $nextDay->id,
            $oldScreenshotUploadedLast->id,
        ];
        $payload = $this->summaryPayload($sourceIds);
        $payload['agreements'] = [[
            'summary' => 'Купити 2 пачки вугілля.',
            'source_ids' => [$laterInFirstBatch->id],
        ], [
            'summary' => 'Зустріч о 15:00.',
            'source_ids' => [$nextDay->id],
        ]];

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame([], $event->state['warnings']);
        $this->assertSame([], $event->state['unresolved_questions']);
        $this->assertSame('Купити 2 пачки вугілля.', $event->state['agreements'][0]['summary']);
        $this->assertSame([$laterInFirstBatch->id], $event->state['agreements'][0]['source_ids']);

        Http::assertSentCount(1);
        /** @var Request $request */
        $request = Http::recorded()->first()[0];
        $systemPrompt = $request['instructions'];
        $userJson = $request['input'][0]['content'][0]['text'];
        $laterPosition = mb_strpos($userJson, '"source_id": '.$laterInFirstBatch->id);
        $olderPosition = mb_strpos($userJson, '"source_id": '.$olderInFirstBatch->id);
        $evidenceSources = collect(json_decode($userJson, true, 512, JSON_THROW_ON_ERROR)['source_batches'])
            ->flatMap(fn (array $batch): array => $batch['sources'])
            ->keyBy('source_id');

        $this->assertStringContainsString('"upload_batch": "'.$firstBatch.'"', $userJson);
        $this->assertStringContainsString('"visible_time": "12:50"', $userJson);
        $this->assertStringContainsString('"visible_time": "11:50"', $userJson);
        $this->assertStringContainsString('"source_id": '.$oldScreenshotUploadedLast->id, $userJson);
        $this->assertStringContainsString('"message_timeline": null', $userJson);
        $this->assertArrayNotHasKey('ocr_text', $evidenceSources->get($laterInFirstBatch->id));
        $this->assertArrayNotHasKey('ocr_text', $evidenceSources->get($olderInFirstBatch->id));
        $this->assertArrayNotHasKey('ocr_text', $evidenceSources->get($nextDay->id));
        $this->assertSame(
            "Іра\nЗустрічаємось о 14:00.\n18.08.2026 09:00",
            $evidenceSources->get($oldScreenshotUploadedLast->id)['ocr_text'],
        );
        $this->assertStringContainsString('position означає лише порядок передавання файлів', $systemPrompt);
        $this->assertStringContainsString('Пізніше завантажений скриншот з явно старішою датою лишається старішим', $systemPrompt);
        $this->assertStringContainsString('це не warning і не unresolved question', $systemPrompt);
        $this->assertStringContainsString('чужа репліка цього не робить', $systemPrompt);
        $this->assertStringContainsString('«Більше не беру» або «треба купити» теж не є brings', $systemPrompt);
        $this->assertStringContainsString('потім «Тарас ніби бере вугілля; це ще не фінально»', $systemPrompt);
        $this->assertStringContainsString('цей товар не може одночасно бути у participants.brings', $systemPrompt);
        $this->assertStringContainsString('Не приписуй алергію Тарасу без явного тексту', $systemPrompt);
        $this->assertStringContainsString('не робить Олю алергіком', $systemPrompt);
        $this->assertStringContainsString('закриває питання про точний алерген', $systemPrompt);
        $this->assertStringContainsString('Фінальний summary також описує лише актуальний стан', $systemPrompt);
        $this->assertStringContainsString('додає ці brings лише Олі, ніколи Саші', $systemPrompt);
        $this->assertStringContainsString('повинна мати цю річ у participants.brings саме цієї людини', $systemPrompt);
        $this->assertStringContainsString('shopping_requirements є структурованим доказовим переліком', $systemPrompt);
        $this->assertStringContainsString('Якщо джерело назвало товар, але не назвало його кількість, quantity=null', $systemPrompt);
        $this->assertStringContainsString('перенеси до shopping_requirements кожну його позицію без винятків', $systemPrompt);
        $this->assertStringContainsString('не додавай механічно до constraints кожної спільної покупки', $systemPrompt);
        $this->assertStringContainsString('Пізніша агрегована кількість для всієї групи не стирає person-level атрибуцію', $systemPrompt);
        $this->assertStringContainsString('якщо товар лишився у shopping_requirements або summary, але зникло відоме авторство бажання', $systemPrompt);
        $this->assertStringContainsString('додай unresolved question про імена решти 3', $systemPrompt);
        $this->assertStringContainsString('Скорочене формулювання не скасовує відомих деталей', $systemPrompt);
        $this->assertIsInt($laterPosition);
        $this->assertIsInt($olderPosition);
        $this->assertLessThan($olderPosition, $laterPosition);
    }

    public function test_plan_correction_is_evidence_but_its_reference_plan_is_not_sent_to_summary(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $correction = EventSource::factory()->for($event)->create([
            'origin' => 'plan_correction',
            'text' => 'Води вдвічі менше.',
            'metadata' => [
                'base_plan_state_version' => 3,
                'base_plan' => ['summary' => 'MUST_NOT_REACH_SUMMARY'],
            ],
        ]);
        $payload = $this->summaryPayload([$correction->id]);
        $payload['agreements'] = [[
            'summary' => 'Для нового списку води потрібно вдвічі менше, ніж у варіанті, який бачив організатор.',
            'source_ids' => [$correction->id],
        ]];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($payload)),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame([$correction->id], $event->state['agreements'][0]['source_ids']);
        Http::assertSent(function (Request $request): bool {
            $systemPrompt = $request['instructions'];
            $userJson = $request['input'][0]['content'][0]['text'];

            return str_contains($userJson, '"origin": "plan_correction"')
                && str_contains($userJson, 'Води вдвічі менше.')
                && str_contains($systemPrompt, 'Збережи її актуальний зміст у agreements')
                && ! str_contains($userJson, 'MUST_NOT_REACH_SUMMARY')
                && ! str_contains($userJson, '"base_plan"');
        });
    }

    public function test_failed_images_produce_an_explicit_partial_summary(): void
    {
        [$event, $taskId] = $this->activeEvent(2);
        $usable = EventSource::factory()->for($event)->create(['text' => 'Саша бере вугілля.']);
        $failed = EventSource::factory()->for($event)->create([
            'text' => null,
            'status' => EventSourceStatus::Failed,
            'inclusion' => EventSourceInclusion::Included,
            'processing_error' => 'OCR failed',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse(
                $this->summaryPayload([$usable->id]),
            )),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(EventAnalysisStage::CompletedWithWarnings, $event->analysis_stage);
        $this->assertSame([$failed->id], $event->state['omitted_source_ids']);
        $this->assertStringContainsString('частковим', collect($event->state['warnings'])->last()['message']);
    }

    public function test_a_stale_ai_response_is_discarded_and_the_same_task_can_synthesize_again(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $first = EventSource::factory()->for($event)->create(['text' => 'Початковий факт.']);
        $requestNumber = 0;
        Http::fake(function () use ($event, $first, &$requestNumber) {
            $requestNumber++;

            if ($requestNumber === 1) {
                EventSource::factory()->for($event)->create([
                    'text' => 'Факт, доданий поки AI думав.',
                    'content_hash' => hash('sha256', 'during-ai'),
                ]);
                $event->increment('evidence_version');
                $event->update(['last_source_at' => now()->subSeconds(10)]);
            }

            $ids = $event->sources()->oldest('id')->pluck('id')->all();

            return Http::response($this->openAiResponse($this->summaryPayload(
                $requestNumber === 1 ? [$first->id] : $ids,
            )));
        });

        $job = new SummarizeEventContextJob($event->id, $taskId);
        $job->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $this->assertNull($event->refresh()->state);
        $this->assertSame($taskId, $event->analysis_task_id);

        $job->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(2, $requestNumber);
        $this->assertSame(EventAnalysisStage::Completed, $event->analysis_stage);
        $this->assertCount(2, $event->state['source_ids']);
    }

    public function test_task_fails_cleanly_when_no_usable_sources_exist(): void
    {
        [$event, $taskId] = $this->activeEvent(0);
        EventSource::factory()->for($event)->create([
            'status' => EventSourceStatus::Processed,
            'inclusion' => EventSourceInclusion::Dismissed,
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(EventStatus::Failed, $event->status);
        $this->assertSame(EventAnalysisStage::Failed, $event->analysis_stage);
        $this->assertStringContainsString('бракує і задуму, і придатних джерел', $event->analysis_error);
        Http::assertNothingSent();
    }

    public function test_organizer_context_can_produce_a_partial_state_without_uploaded_sources(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $event->update([
            'title' => 'Пікнік біля води',
            'description' => 'Хочемо пікнік на озері й щось нове від Гуся.',
            'alcohol_planned' => true,
            'people_count' => null,
            'budget_amount' => null,
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'summary' => 'Пікнік біля озера без уточненого складу компанії.',
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [[
                    'question_key' => '__new__',
                    'question' => 'Скільки людей буде на пікніку?',
                    'impact' => 'Від цього залежать усі кількості.',
                    'blocking' => true,
                    'options' => [[
                        'label' => 'Уточнити точну кількість',
                        'description' => 'Найбезпечніше перед розрахунком.',
                        'recommended' => true,
                    ], [
                        'label' => 'Поки не складати список',
                        'description' => 'Повернутися, коли кількість буде відома.',
                        'recommended' => false,
                    ], [
                        'label' => 'Назвати приблизний діапазон',
                        'description' => 'Гусь складе обережний чернетковий розрахунок.',
                        'recommended' => false,
                    ]],
                    'source_ids' => [],
                ], [
                    'question_key' => '__new__',
                    'question' => 'Чи потрібно додавати алкоголь до плану?',
                    'impact' => 'Це змінить список напоїв.',
                    'blocking' => false,
                    'options' => [[
                        'label' => 'Вважати алкоголь запланованим',
                        'description' => 'Організатор уже підтвердив це під час створення.',
                        'recommended' => true,
                    ], [
                        'label' => 'Не додавати алкоголь',
                        'description' => 'Залишити лише безалкогольні напої.',
                        'recommended' => false,
                    ], [
                        'label' => 'Уточнити пізніше',
                        'description' => 'Повернутися до напоїв згодом.',
                        'recommended' => false,
                    ]],
                    'source_ids' => [],
                ]],
                'source_ids' => [],
            ])),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(EventStatus::Ready, $event->status);
        $this->assertSame(EventAnalysisStage::Completed, $event->analysis_stage);
        $this->assertSame(1, $event->state_evidence_version);
        $this->assertSame([], $event->state['source_ids']);
        $this->assertCount(1, $event->state['unresolved_questions']);
        $this->assertSame([], $event->state['unresolved_questions'][0]['source_ids']);
        $this->assertStringNotContainsString('алкоголь', Str::lower($event->state['unresolved_questions'][0]['question']));
        $this->assertStringStartsWith('q_', $event->state['unresolved_questions'][0]['key']);
        Http::assertSent(function (Request $request): bool {
            $systemPrompt = $request['instructions'];
            $userJson = $request['input'][0]['content'][0]['text'];

            return str_contains($userJson, '"organizer_context"')
                && str_contains($userJson, 'Хочемо пікнік на озері й щось нове від Гуся.')
                && str_contains($userJson, '"alcohol_planned": true')
                && str_contains($userJson, '"source_batches": []')
                && str_contains($systemPrompt, 'не питай, чи потрібен алкоголь')
                && str_contains($systemPrompt, 'Не проси організатора самому скласти точний список покупок')
                && str_contains($systemPrompt, 'назвати базові продукти для формату')
                && str_contains($systemPrompt, 'це робить застосунок')
                && str_contains($systemPrompt, 'source_ids має бути порожнім масивом')
                && str_contains($systemPrompt, 'Не вигадуй учасників, кількості, алергії');
        });
    }

    public function test_answered_question_key_cannot_be_reopened_by_a_paraphrased_summary(): void
    {
        [$event, $taskId] = $this->activeEvent(1);
        $event->update([
            'state_version' => 1,
            'state' => [
                'summary' => 'Пікнік без списку імен.',
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [[
                    'key' => 'q_names_current',
                    'question' => 'Потрібні імена решти гостей?',
                    'impact' => 'Імена допоможуть персоналізувати список.',
                    'blocking' => false,
                    'options' => [],
                    'source_ids' => [],
                ]],
                'source_ids' => [],
            ],
        ]);
        $answerSource = EventSource::factory()->for($event)->create([
            'type' => EventSourceType::Text,
            'origin' => 'question_answer',
            'metadata' => [
                'question_key' => 'q_names',
                'question' => 'Потрібні імена решти гостей?',
                'answer' => 'Залишити без імен',
                'state_version' => 1,
            ],
            'text' => 'Відповідь організатора: залишити без імен.',
            'status' => EventSourceStatus::Processed,
            'inclusion' => EventSourceInclusion::Included,
        ]);
        $currentState = $event->state;
        $currentState['unresolved_questions'][0]['options'] = [[
            'label' => 'Залишити без імен',
            'description' => 'Не персоналізувати список.',
            'recommended' => true,
        ]];
        $currentState['unresolved_questions'][0]['source_ids'] = [$answerSource->id];
        $event->update(['state' => $currentState]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse([
                'summary' => 'Пікнік без персоналізації за іменами.',
                'participants' => [[
                    'name' => '8 учасників; решта 3 без імен',
                    'status' => 'confirmed',
                    'preferences' => [],
                    'restrictions' => [],
                    'allergies' => [],
                    'brings' => [],
                    'source_ids' => [$answerSource->id],
                ]],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [[
                    'question_key' => 'q_names_current',
                    'question' => 'Чи треба все ж уточнити імена гостей?',
                    'impact' => 'Це вплине на персоналізацію.',
                    'blocking' => false,
                    'options' => [[
                        'label' => 'Залишити без імен',
                        'description' => 'Організатор уже так вирішив.',
                        'recommended' => true,
                    ], [
                        'label' => 'Уточнити імена',
                        'description' => 'Зібрати додаткові дані.',
                        'recommended' => false,
                    ], [
                        'label' => 'Повернутися пізніше',
                        'description' => 'Не затримувати поточний план.',
                        'recommended' => false,
                    ]],
                    'source_ids' => [$answerSource->id],
                ]],
                'source_ids' => [$answerSource->id],
            ])),
        ]);

        (new SummarizeEventContextJob($event->id, $taskId))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $this->assertSame([], $event->state['participants']);
        $this->assertSame([], $event->state['unresolved_questions']);
        $harnessRun = $event->harnessRuns()->with('entries')->sole();
        $this->assertSame('completed', $harnessRun->status->value);
        $this->assertTrue($harnessRun->entries->contains(
            fn ($entry): bool => $entry->kind->value === 'llm'
                && $entry->request_payload !== null
                && $entry->response_payload !== null,
        ));
        Http::assertSent(function (Request $request): bool {
            $systemPrompt = $request['instructions'];
            $userJson = $request['input'][0]['content'][0]['text'];

            return str_contains($userJson, '"question_ledger"')
                && str_contains($userJson, '"key": "q_names_current"')
                && str_contains($userJson, 'Залишити без імен')
                && str_contains($systemPrompt, 'ніколи не повертай питання з answered');
        });
    }

    /** @return array{Event, string} */
    private function activeEvent(int $evidenceVersion): array
    {
        $taskId = (string) Str::ulid();
        $event = Event::factory()->for(User::factory())->create([
            'status' => EventStatus::Processing,
            'evidence_version' => $evidenceVersion,
            'analysis_task_id' => $taskId,
            'analysis_stage' => EventAnalysisStage::Summarizing,
            'analysis_started_at' => now()->subMinute(),
            'last_source_at' => now()->subSeconds(10),
        ]);

        return [$event, $taskId];
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $messageTimeline
     */
    private function imageSource(
        Event $event,
        string $batch,
        int $position,
        string $ocr,
        ?array $messageTimeline,
        DateTimeInterface $createdAt,
    ): EventSource {
        $extraction = ImageExtraction::factory()->for($event->user)->create([
            'classification' => ImageClassification::ChatScreenshot,
            'ocr_text' => $ocr,
            'message_timeline' => $messageTimeline,
            'source_summary' => $ocr,
        ]);

        return EventSource::factory()->for($event)->create([
            'image_extraction_id' => $extraction->id,
            'type' => EventSourceType::Image,
            'text' => null,
            'upload_batch' => $batch,
            'position' => $position,
            'content_hash' => hash('sha256', $batch.$position.$ocr),
            'created_at' => $createdAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function message(string $author, string $text, string $visibleDate, string $visibleTime): array
    {
        return [
            'sequence' => 0,
            'author' => $author,
            'text' => $text,
            'visible_date' => $visibleDate,
            'visible_time' => $visibleTime,
            'is_quoted' => false,
        ];
    }

    /** @param array<int, int> $sourceIds */
    private function summaryPayload(array $sourceIds): array
    {
        return [
            'summary' => 'Шашлик із уточненими домовленостями.',
            'participants' => [[
                'name' => 'Оля',
                'status' => 'confirmed',
                'preferences' => ['овочі'],
                'restrictions' => ['без мʼяса'],
                'allergies' => [],
                'brings' => [],
                'source_ids' => [$sourceIds[0]],
            ], [
                'name' => 'Марта',
                'status' => 'confirmed',
                'preferences' => [],
                'restrictions' => ['без арахісу'],
                'allergies' => ['арахіс'],
                'brings' => [],
                'source_ids' => [$sourceIds[0]],
            ]],
            'restrictions' => [[
                'participant' => 'Оля',
                'restriction' => 'без мʼяса',
                'severity' => 'hard',
                'source_ids' => [$sourceIds[0]],
            ]],
            'agreements' => [],
            'warnings' => [],
            'unresolved_questions' => [],
            'source_ids' => $sourceIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function openAiResponse(array $payload): array
    {
        return [
            'output' => [[
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ];
    }
}
