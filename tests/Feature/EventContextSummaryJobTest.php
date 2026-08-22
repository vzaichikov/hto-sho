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
            ->handle($this->app->make(ContextAnalysisService::class));

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
            ->handle($this->app->make(ContextAnalysisService::class));
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
            ->handle($this->app->make(ContextAnalysisService::class));

        $event->refresh();
        $this->assertSame([], $event->state['warnings']);
        $this->assertSame([], $event->state['unresolved_questions']);
        $this->assertSame('Купити 2 пачки вугілля.', $event->state['agreements'][0]['summary']);
        $this->assertSame([$laterInFirstBatch->id], $event->state['agreements'][0]['source_ids']);

        Http::assertSent(function (Request $request) use (
            $firstBatch,
            $laterInFirstBatch,
            $olderInFirstBatch,
            $oldScreenshotUploadedLast,
        ): bool {
            $prompt = $request['input'][0]['content'][0]['text'];
            $laterPosition = mb_strpos($prompt, '"source_id": '.$laterInFirstBatch->id);
            $olderPosition = mb_strpos($prompt, '"source_id": '.$olderInFirstBatch->id);

            return str_contains($prompt, '"upload_batch": "'.$firstBatch.'"')
                && str_contains($prompt, '"visible_time": "12:50"')
                && str_contains($prompt, '"visible_time": "11:50"')
                && str_contains($prompt, '"source_id": '.$oldScreenshotUploadedLast->id)
                && str_contains($prompt, '"message_timeline": null')
                && str_contains($prompt, 'position означає лише порядок передавання файлів')
                && str_contains($prompt, 'Пізніше завантажений скриншот з явно старішою датою лишається старішим')
                && str_contains($prompt, 'це не warning і не unresolved question')
                && str_contains($prompt, 'чужа репліка цього не робить')
                && str_contains($prompt, '«Більше не беру» або «треба купити» не є brings')
                && str_contains($prompt, 'Не приписуй алергію Тарасу без явного тексту')
                && str_contains($prompt, 'Фінальний summary також описує лише актуальний стан')
                && str_contains($prompt, 'додає ці brings лише Олі, ніколи Саші')
                && str_contains($prompt, 'додай unresolved question про імена решти 3')
                && is_int($laterPosition)
                && is_int($olderPosition)
                && $laterPosition < $olderPosition;
        });
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
            ->handle($this->app->make(ContextAnalysisService::class));

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
            ->handle($this->app->make(ContextAnalysisService::class));

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
        $job->handle($this->app->make(ContextAnalysisService::class));

        $this->assertNull($event->refresh()->state);
        $this->assertSame($taskId, $event->analysis_task_id);

        $job->handle($this->app->make(ContextAnalysisService::class));

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
            ->handle($this->app->make(ContextAnalysisService::class));

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
            ->handle($this->app->make(ContextAnalysisService::class));

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
                && str_contains($prompt, 'ПАЧКИ ДЖЕРЕЛ:'."\n".'[]')
                && str_contains($prompt, 'source_ids має бути порожнім масивом')
                && str_contains($prompt, 'Не вигадуй учасників, кількості, алергії');
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
