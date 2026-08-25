<?php

namespace Tests\Feature;

use App\CartSyncStatus;
use App\Data\ImageExtractionData;
use App\EventAnalysisStage;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use App\Models\User;
use App\PlanGenerationStatus;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AiProductLogicScenarioRepository;
use Tests\Support\AssertsAiProductLogic;
use Tests\TestCase;

class AiProductLogicRegressionTest extends TestCase
{
    use AssertsAiProductLogic;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('local');
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.model' => 'gpt-5.4-mini',
            'services.ai.api_key' => 'regression-test-key',
        ]);
    }

    public function test_regression_expectations_never_assert_summary_wording(): void
    {
        $scenarios = AiProductLogicScenarioRepository::all();

        $this->assertCount(15, $scenarios, 'The product AI regression pack must retain all fifteen baseline scenarios.');

        foreach ($scenarios as $scenario) {
            $this->assertFalse(
                $this->containsRecursiveKey($scenario['expect'], 'summary'),
                'AI regression expectations must not assert summary wording: '.AiProductLogicScenarioRepository::label($scenario),
            );
        }
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('contextScenarios')]
    public function test_context_synthesis_preserves_product_semantics(array $scenario): void
    {
        $scenarioLabel = AiProductLogicScenarioRepository::label($scenario);
        [$event, $sourceIdMap] = $this->eventWithContextSources($scenario);
        $modelResponse = $this->replaceFixtureSourceIds($scenario['model_response'], $sourceIdMap);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($modelResponse)),
        ]);

        (new SummarizeEventContextJob($event->id, $event->analysis_task_id))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $expectations = $this->replaceFixtureSourceIds($scenario['expect'], $sourceIdMap);

        $this->assertSame(EventStatus::Ready, $event->status, $this->failure($scenarioLabel, 'Event context was not committed.'));
        $this->assertSame(
            $expectations['event']['people_count'] ?? null,
            $event->people_count,
            $this->failure($scenarioLabel, 'The known people_count changed during synthesis.'),
        );
        $this->assertAiState($event->state, $expectations['state'], $scenarioLabel);
        $this->assertSame(
            $event->evidence_version,
            $event->state_evidence_version,
            $this->failure($scenarioLabel, 'Committed state does not reference the current evidence revision.'),
        );
        Http::assertSentCount(1);
        $this->assertContextPromptContainsScenario($scenario, $sourceIdMap);
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('imageScenarios')]
    public function test_image_intake_and_context_use_the_same_product_pipeline(array $scenario): void
    {
        $scenarioLabel = AiProductLogicScenarioRepository::label($scenario);
        [$event, $source, $extraction] = $this->eventWithPendingImage($scenario);
        $sourceIdMap = [$scenario['image']['source_id'] => $source->id];
        $contextResponse = $this->replaceFixtureSourceIds($scenario['model_response']['context'], $sourceIdMap);

        Http::fake([
            'https://api.openai.com/v1/responses' => Http::sequence()
                ->push($this->openAiResponse($scenario['model_response']['image']))
                ->push($this->openAiResponse($contextResponse)),
        ]);

        (new ProcessImageExtractionJob($extraction->id))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $extraction->refresh();
        $source->refresh();
        $persistedImage = ImageExtractionData::from([
            'classification' => $extraction->classification->value,
            'ocr_text' => $extraction->ocr_text,
            'message_timeline' => $extraction->message_timeline,
            'summary' => $extraction->source_summary,
            'dismissal_reason' => $extraction->dismissal_reason,
        ]);
        $this->assertAiImage($persistedImage, $scenario['expect']['image'], $scenarioLabel);
        $this->assertSame(
            $scenario['expect']['image']['inclusion'],
            $source->inclusion->value,
            $this->failure($scenarioLabel, 'Image inclusion does not match its classification.'),
        );

        (new SummarizeEventContextJob($event->id, $event->analysis_task_id))->handle(
            $this->app->make(ContextAnalysisService::class),
            $this->app->make(HarnessRecorder::class),
        );

        $event->refresh();
        $expectations = $this->replaceFixtureSourceIds($scenario['expect'], $sourceIdMap);

        $this->assertSame(EventStatus::Ready, $event->status, $this->failure($scenarioLabel, 'Image context was not committed.'));
        $this->assertSame(
            $expectations['event']['people_count'],
            $event->people_count,
            $this->failure($scenarioLabel, 'Image processing changed people_count.'),
        );
        $this->assertAiState($event->state, $expectations['state'], $scenarioLabel);
        Http::assertSentCount(2);
        $this->assertImageContextInclusionWasApplied($scenario, $source->id);
    }

    /** @param array<string, mixed> $scenario */
    #[DataProvider('stalenessScenarios')]
    public function test_new_evidence_invalidates_derived_state_and_plan(array $scenario): void
    {
        $scenarioLabel = AiProductLogicScenarioRepository::label($scenario);
        $user = User::factory()->create();
        $initial = $scenario['initial_event'];
        $event = Event::factory()->for($user)->create([
            ...$initial,
            'status' => EventStatus::Ready,
            'plan_generation_status' => PlanGenerationStatus::Ready,
            'cart_sync_status' => CartSyncStatus::Synced,
            'cart_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), ['text' => $scenario['new_source']['text']])
            ->assertRedirect();

        $event->refresh();

        $this->assertSame(
            $scenario['new_source']['expected_evidence_version'],
            $event->evidence_version,
            $this->failure($scenarioLabel, 'Evidence revision did not advance.'),
        );
        $this->assertSame($scenario['expect']['state_version'], $event->state_version);
        $this->assertSame($scenario['expect']['state_evidence_version'], $event->state_evidence_version);
        $this->assertSame($scenario['expect']['plan_state_version'], $event->plan_state_version);
        $this->assertSame($scenario['expect']['cart_synced_state_version'], $event->cart_synced_state_version);
        $this->assertSame($scenario['expect']['cart_sync_status'], $event->cart_sync_status->value);
        $this->assertSame($initial['state'], $event->state, $this->failure($scenarioLabel, 'Old state should remain traceable while stale.'));
        $this->assertSame($initial['shopping_plan'], $event->shopping_plan, $this->failure($scenarioLabel, 'Old plan should remain traceable while stale.'));
        $this->assertSame($scenario['expect']['has_unanalyzed_changes'], $event->hasUnanalyzedChanges());
        $this->assertSame($scenario['expect']['plan_current'], $event->isPlanCurrent());
        $this->assertSame($scenario['expect']['cart_current'], $event->isCartCurrent());
        Queue::assertPushed(SummarizeEventContextJob::class);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function contextScenarios(): array
    {
        return AiProductLogicScenarioRepository::forPipelines('context');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function imageScenarios(): array
    {
        return AiProductLogicScenarioRepository::forPipelines('image_context');
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function stalenessScenarios(): array
    {
        return AiProductLogicScenarioRepository::forPipelines('staleness');
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array{Event, array<int, int>}
     */
    private function eventWithContextSources(array $scenario): array
    {
        $sourceCount = collect($scenario['source_batches'])->sum(
            fn (array $batch): int => count($batch['sources']),
        );
        $event = $this->activeEvent($scenario['organizer_context'], $sourceCount);
        $sourceIdMap = [];

        foreach ($scenario['source_batches'] as $batch) {
            foreach ($batch['sources'] as $sourceFixture) {
                $source = $this->createContextSource($event, $batch, $sourceFixture);
                $sourceIdMap[$sourceFixture['source_id']] = $source->id;
            }
        }

        return [$event, $sourceIdMap];
    }

    /** @param array<string, mixed> $organizerContext */
    private function activeEvent(array $organizerContext, int $evidenceVersion): Event
    {
        return Event::factory()->for(User::factory())->create([
            ...$organizerContext,
            'status' => EventStatus::Processing,
            'evidence_version' => $evidenceVersion,
            'analysis_task_id' => (string) Str::ulid(),
            'analysis_stage' => EventAnalysisStage::Summarizing,
            'analysis_started_at' => now()->subMinute(),
            'last_source_at' => now()->subSeconds(10),
        ]);
    }

    /**
     * @param  array<string, mixed>  $batch
     * @param  array<string, mixed>  $sourceFixture
     */
    private function createContextSource(Event $event, array $batch, array $sourceFixture): EventSource
    {
        $type = EventSourceType::from($sourceFixture['kind']);
        $attributes = [
            'type' => $type,
            'text' => $type === EventSourceType::Text ? $sourceFixture['text'] : null,
            'upload_batch' => $batch['upload_batch'],
            'position' => $sourceFixture['position'],
            'origin' => $sourceFixture['origin'],
            'content_hash' => hash('sha256', json_encode($sourceFixture, JSON_THROW_ON_ERROR)),
            'status' => EventSourceStatus::Processed,
            'inclusion' => EventSourceInclusion::Included,
            'created_at' => CarbonImmutable::parse($sourceFixture['uploaded_at']),
            'processed_at' => CarbonImmutable::parse($sourceFixture['uploaded_at']),
        ];

        if ($type === EventSourceType::Image) {
            $extraction = ImageExtraction::factory()->for($event->user)->create([
                'content_hash' => hash('sha256', 'extraction-'.json_encode($sourceFixture, JSON_THROW_ON_ERROR)),
                'status' => ImageExtractionStatus::Processed,
                'classification' => ImageClassification::from($sourceFixture['classification']),
                'ocr_text' => $sourceFixture['ocr_text'],
                'message_timeline' => $sourceFixture['message_timeline'],
                'source_summary' => $sourceFixture['source_summary'],
                'processed_at' => CarbonImmutable::parse($sourceFixture['uploaded_at']),
            ]);
            $attributes['image_extraction_id'] = $extraction->id;
        }

        return EventSource::factory()->for($event)->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @return array{Event, EventSource, ImageExtraction}
     */
    private function eventWithPendingImage(array $scenario): array
    {
        $event = $this->activeEvent($scenario['organizer_context'], 1);
        $imagePath = __DIR__.'/../Fixtures/AiProductLogic/'.$scenario['image']['path'];
        $contents = file_get_contents($imagePath);
        $this->assertIsString($contents, $this->failure($scenario['id'], 'Image fixture cannot be read.'));

        $extraction = ImageExtraction::factory()->for($event->user)->create([
            'content_hash' => hash('sha256', $contents),
            'status' => ImageExtractionStatus::Pending,
            'classification' => null,
            'ocr_text' => null,
            'message_timeline' => null,
            'source_summary' => null,
            'processed_at' => null,
        ]);
        $privatePath = "events/{$event->user_id}/{$event->id}/regression.png";
        Storage::disk('local')->put($privatePath, $contents);
        $source = EventSource::factory()->for($event)->create([
            'image_extraction_id' => $extraction->id,
            'type' => EventSourceType::Image,
            'text' => null,
            'file_path' => $privatePath,
            'original_name' => basename($scenario['image']['path']),
            'mime_type' => $scenario['image']['mime_type'],
            'size' => strlen($contents),
            'upload_batch' => $scenario['image']['upload_batch'],
            'position' => $scenario['image']['position'],
            'content_hash' => hash('sha256', $contents),
            'status' => EventSourceStatus::Pending,
            'inclusion' => EventSourceInclusion::Included,
            'processed_at' => null,
            'created_at' => CarbonImmutable::parse($scenario['image']['uploaded_at']),
        ]);

        return [$event, $source, $extraction];
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  array<int, int>  $sourceIdMap
     * @return array<string, mixed>
     */
    private function replaceFixtureSourceIds(array $value, array $sourceIdMap): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'source_ids' && is_array($item)) {
                $value[$key] = array_map(
                    fn (int $sourceId): int => $sourceIdMap[$sourceId] ?? $sourceId,
                    $item,
                );

                continue;
            }

            if (is_array($item)) {
                $value[$key] = $this->replaceFixtureSourceIds($item, $sourceIdMap);
            }
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<int, int>  $sourceIdMap
     */
    private function assertContextPromptContainsScenario(array $scenario, array $sourceIdMap): void
    {
        Http::assertSent(function (Request $request) use ($scenario, $sourceIdMap): bool {
            if (data_get($request->data(), 'text.format.name') !== 'event_context') {
                return false;
            }

            $prompt = (string) data_get($request->data(), 'input.0.content.0.text');

            foreach ($scenario['source_batches'] as $batch) {
                if (! str_contains($prompt, '"upload_batch": "'.$batch['upload_batch'].'"')) {
                    return false;
                }

                foreach ($batch['sources'] as $source) {
                    if (! str_contains($prompt, '"source_id": '.$sourceIdMap[$source['source_id']])) {
                        return false;
                    }

                    $evidenceTexts = $source['kind'] === 'text'
                        ? [$source['text']]
                        : collect($source['message_timeline'])->pluck('text')->all();

                    foreach ($evidenceTexts as $evidenceText) {
                        $encodedText = json_encode($evidenceText, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                        if (! str_contains($prompt, $encodedText)) {
                            return false;
                        }
                    }
                }
            }

            return true;
        });
    }

    /** @param array<string, mixed> $scenario */
    private function assertImageContextInclusionWasApplied(array $scenario, int $sourceId): void
    {
        $shouldBeIncluded = $scenario['expect']['image']['inclusion'] === EventSourceInclusion::Included->value;

        Http::assertSent(function (Request $request) use ($shouldBeIncluded, $sourceId): bool {
            if (data_get($request->data(), 'text.format.name') !== 'event_context') {
                return false;
            }

            $prompt = (string) data_get($request->data(), 'input.0.content.0.text');
            $containsSource = str_contains($prompt, '"source_id": '.$sourceId);

            return $shouldBeIncluded === $containsSource;
        });
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
                    'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]],
            ]],
        ];
    }
}
