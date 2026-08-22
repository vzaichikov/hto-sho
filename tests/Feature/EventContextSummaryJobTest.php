<?php

namespace Tests\Feature;

use App\EventAnalysisStage;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\ImageClassification;
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
use Illuminate\Support\Str;
use Tests\TestCase;

class EventContextSummaryJobTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
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
        $this->assertStringContainsString('Немає придатних джерел', $event->analysis_error);
        Http::assertNothingSent();
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
