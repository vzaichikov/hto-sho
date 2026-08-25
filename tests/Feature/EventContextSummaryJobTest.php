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

        $this->assertSame(150, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);
        $this->assertGreaterThanOrEqual(20, $job->tries);
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
            $request['input'][0]['content'][0]['text'],
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
        $repairPrompt = (string) data_get(Http::recorded()[1][0]->data(), 'input.0.content.0.text');
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
            $record[0]['input'][0]['content'][0]['text'],
            'Ти один раз перевіряєш повноту',
        )));
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
            $prompt = $request['input'][0]['content'][0]['text'];

            return mb_strpos($prompt, 'Спочатку: зустріч у суботу.')
                < mb_strpos($prompt, 'Уточнення: перенесли на неділю.');
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
        $prompt = $request['input'][0]['content'][0]['text'];
        $laterPosition = mb_strpos($prompt, '"source_id": '.$laterInFirstBatch->id);
        $olderPosition = mb_strpos($prompt, '"source_id": '.$olderInFirstBatch->id);

        $this->assertStringContainsString('"upload_batch": "'.$firstBatch.'"', $prompt);
        $this->assertStringContainsString('"visible_time": "12:50"', $prompt);
        $this->assertStringContainsString('"visible_time": "11:50"', $prompt);
        $this->assertStringContainsString('"source_id": '.$oldScreenshotUploadedLast->id, $prompt);
        $this->assertStringContainsString('"message_timeline": null', $prompt);
        $this->assertStringContainsString('position означає лише порядок передавання файлів', $prompt);
        $this->assertStringContainsString('Пізніше завантажений скриншот з явно старішою датою лишається старішим', $prompt);
        $this->assertStringContainsString('це не warning і не unresolved question', $prompt);
        $this->assertStringContainsString('чужа репліка цього не робить', $prompt);
        $this->assertStringContainsString('«Більше не беру» або «треба купити» теж не є brings', $prompt);
        $this->assertStringContainsString('потім «Тарас ніби бере вугілля; це ще не фінально»', $prompt);
        $this->assertStringContainsString('цей товар не може одночасно бути у participants.brings', $prompt);
        $this->assertStringContainsString('Не приписуй алергію Тарасу без явного тексту', $prompt);
        $this->assertStringContainsString('не робить Олю алергіком', $prompt);
        $this->assertStringContainsString('закриває питання про точний алерген', $prompt);
        $this->assertStringContainsString('Фінальний summary також описує лише актуальний стан', $prompt);
        $this->assertStringContainsString('додає ці brings лише Олі, ніколи Саші', $prompt);
        $this->assertStringContainsString('повинна мати цю річ у participants.brings саме цієї людини', $prompt);
        $this->assertStringContainsString('shopping_requirements є структурованим доказовим переліком', $prompt);
        $this->assertStringContainsString('Якщо джерело назвало товар, але не назвало його кількість, quantity=null', $prompt);
        $this->assertStringContainsString('перенеси до shopping_requirements кожну його позицію без винятків', $prompt);
        $this->assertStringContainsString('не додавай механічно до constraints кожної спільної покупки', $prompt);
        $this->assertStringContainsString('Пізніша агрегована кількість для всієї групи не стирає person-level атрибуцію', $prompt);
        $this->assertStringContainsString('якщо товар лишився у shopping_requirements або summary, але зникло відоме авторство бажання', $prompt);
        $this->assertStringContainsString('додай unresolved question про імена решти 3', $prompt);
        $this->assertStringContainsString('Скорочене формулювання не скасовує відомих деталей', $prompt);
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
            $prompt = $request['input'][0]['content'][0]['text'];

            return str_contains($prompt, '"origin": "plan_correction"')
                && str_contains($prompt, 'Води вдвічі менше.')
                && str_contains($prompt, 'Збережи її актуальний зміст у agreements')
                && ! str_contains($prompt, 'MUST_NOT_REACH_SUMMARY')
                && ! str_contains($prompt, '"base_plan"');
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
            $prompt = $request['input'][0]['content'][0]['text'];

            return str_contains($prompt, 'КОНТЕКСТ ОРГАНІЗАТОРА')
                && str_contains($prompt, 'Хочемо пікнік на озері й щось нове від Гуся.')
                && str_contains($prompt, '"alcohol_planned": true')
                && str_contains($prompt, 'не питай, чи потрібен алкоголь')
                && str_contains($prompt, 'Не проси організатора самому скласти точний список покупок')
                && str_contains($prompt, 'назвати базові продукти для формату')
                && str_contains($prompt, 'це робить застосунок')
                && str_contains($prompt, 'ПАЧКИ ДЖЕРЕЛ:'."\n".'[]')
                && str_contains($prompt, 'source_ids має бути порожнім масивом')
                && str_contains($prompt, 'Не вигадуй учасників, кількості, алергії');
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
            $prompt = $request['input'][0]['content'][0]['text'];

            return str_contains($prompt, 'ЖУРНАЛ ПИТАНЬ:')
                && str_contains($prompt, '"key": "q_names_current"')
                && str_contains($prompt, 'Залишити без імен')
                && str_contains($prompt, 'ніколи не повертай питання з answered');
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
