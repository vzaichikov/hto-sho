<?php

namespace Tests\Feature;

use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\ImageExtraction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContextHarnessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();
    }

    public function test_sha_cache_is_reused_inside_one_account_but_never_between_accounts(): void
    {
        $owner = User::factory()->create();
        $firstEvent = Event::factory()->for($owner)->create();
        $secondEvent = Event::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('events.sources.store', $firstEvent), [
            'images' => [$this->fakePng('chat.png')],
        ])->assertRedirect();

        $firstSource = $firstEvent->sources()->sole();
        $extraction = $firstSource->imageExtraction;
        $extraction->update([
            'status' => ImageExtractionStatus::Processed,
            'classification' => ImageClassification::ChatScreenshot,
            'ocr_text' => 'Оля: я без мʼяса',
            'message_timeline' => [[
                'sequence' => 0,
                'author' => 'Оля',
                'text' => 'Я без мʼяса',
                'visible_date' => null,
                'visible_time' => '10:18',
                'is_quoted' => false,
            ]],
            'source_summary' => 'Оля просить їжу без мʼяса.',
            'processed_at' => now(),
        ]);
        $firstSource->update([
            'status' => EventSourceStatus::Processed,
            'processed_at' => now(),
        ]);

        Queue::fake();
        $this->actingAs($owner)->post(route('events.sources.store', $secondEvent), [
            'images' => [$this->fakePng('same-chat.png')],
        ])->assertRedirect();

        $cachedSource = $secondEvent->sources()->with('imageExtraction')->sole();
        $this->assertSame($extraction->id, $cachedSource->image_extraction_id);
        $this->assertTrue($cachedSource->used_cached_extraction);
        $this->assertSame(EventSourceStatus::Processed, $cachedSource->status);
        $this->assertSame('Оля: я без мʼяса', $cachedSource->imageExtraction->ocr_text);
        $this->assertSame('10:18', $cachedSource->imageExtraction->message_timeline[0]['visible_time']);
        Queue::assertNotPushed(ProcessImageExtractionJob::class);

        $otherOwner = User::factory()->create();
        $otherEvent = Event::factory()->for($otherOwner)->create();
        $this->actingAs($otherOwner)->post(route('events.sources.store', $otherEvent), [
            'images' => [$this->fakePng('same-chat.png')],
        ])->assertRedirect();

        $this->assertSame(2, ImageExtraction::query()->where('content_hash', $extraction->content_hash)->count());
        $this->assertNotSame($extraction->id, $otherEvent->sources()->sole()->image_extraction_id);
        Queue::assertPushed(ProcessImageExtractionJob::class, 1);
    }

    public function test_deleting_the_last_account_reference_removes_cache_and_private_files(): void
    {
        $user = User::factory()->create();
        $firstEvent = Event::factory()->for($user)->create();
        $secondEvent = Event::factory()->for($user)->create();

        $this->actingAs($user)->post(route('events.sources.store', $firstEvent), [
            'images' => [$this->fakePng('first.png')],
        ]);
        $firstSource = $firstEvent->sources()->sole();
        $firstSource->imageExtraction->update([
            'status' => ImageExtractionStatus::Processed,
            'classification' => ImageClassification::ProductImage,
            'ocr_text' => 'Вугілля 2,5 кг',
            'source_summary' => 'Пакет вугілля.',
            'processed_at' => now(),
        ]);
        $firstSource->update(['status' => EventSourceStatus::Processed, 'processed_at' => now()]);

        $this->actingAs($user)->post(route('events.sources.store', $secondEvent), [
            'images' => [$this->fakePng('second.png')],
        ]);
        $secondSource = $secondEvent->sources()->sole();
        $extractionId = $firstSource->image_extraction_id;

        $this->actingAs($user)
            ->delete(route('events.sources.destroy', [$firstEvent, $firstSource]))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($firstSource->file_path);
        $this->assertDatabaseHas('image_extractions', ['id' => $extractionId]);

        $this->actingAs($user)
            ->delete(route('events.sources.destroy', [$secondEvent, $secondSource]))
            ->assertRedirect();

        Storage::disk('local')->assertMissing($secondSource->file_path);
        $this->assertDatabaseMissing('image_extractions', ['id' => $extractionId]);
    }

    public function test_irrelevant_image_can_be_forced_and_dismissed_without_another_ocr_job(): void
    {
        $owner = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $extraction = ImageExtraction::factory()->for($owner)->create([
            'classification' => ImageClassification::Irrelevant,
            'dismissal_reason' => 'Схоже на краєвид без чату чи продуктів.',
        ]);
        $source = EventSource::factory()->for($event)->create([
            'image_extraction_id' => $extraction->id,
            'type' => EventSourceType::Image,
            'text' => null,
            'inclusion' => EventSourceInclusion::Dismissed,
        ]);

        $this->actingAs($owner)->patch(route('events.sources.inclusion', [$event, $source]), [
            'inclusion' => EventSourceInclusion::Forced->value,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(EventSourceInclusion::Forced, $source->refresh()->inclusion);
        $this->assertSame(1, $event->refresh()->evidence_version);
        Queue::assertNotPushed(ProcessImageExtractionJob::class);

        $this->actingAs($owner)->patch(route('events.sources.inclusion', [$event, $source]), [
            'inclusion' => EventSourceInclusion::Dismissed->value,
        ])->assertRedirect();

        $this->assertSame(EventSourceInclusion::Dismissed, $source->refresh()->inclusion);
        $this->assertSame(2, $event->refresh()->evidence_version);
    }

    public function test_manual_analysis_is_idempotent_and_new_sources_join_the_active_task(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        EventSource::factory()->for($event)->create(['text' => 'Старий факт']);

        $first = $this->actingAs($user)
            ->postJson(route('events.analysis.store', $event))
            ->assertAccepted()
            ->assertJsonPath('stage', 'waiting_for_quiet');
        $taskId = $first->json('task_id');

        $this->actingAs($user)
            ->postJson(route('events.analysis.store', $event))
            ->assertAccepted()
            ->assertJsonPath('task_id', $taskId);

        $this->actingAs($user)->post(route('events.sources.store', $event), [
            'text' => 'Новий факт під час аналізу',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame($taskId, $event->analysis_task_id);
        $this->assertSame(1, $event->evidence_version);
        Queue::assertPushed(SummarizeEventContextJob::class, fn (SummarizeEventContextJob $job): bool => $job->taskId === $taskId);
    }

    public function test_routes_are_owner_scoped_and_nested_source_binding_cannot_cross_events(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();
        $otherEvent = Event::factory()->for($owner)->create();
        $source = EventSource::factory()->for($event)->create();

        $this->actingAs($stranger)
            ->delete(route('events.sources.destroy', [$event, $source]))
            ->assertForbidden();
        $this->actingAs($owner)
            ->post(route('events.sources.retry', [$otherEvent, $source]))
            ->assertNotFound();
    }

    public function test_history_is_chronological_and_ui_contains_polling_progress_and_reduced_motion_hooks(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        EventSource::factory()->for($event)->create([
            'text' => 'Спочатку домовились про суботу.',
            'content_hash' => hash('sha256', 'older'),
            'created_at' => now()->subMinute(),
        ]);
        EventSource::factory()->for($event)->create([
            'text' => 'Потім перенесли на неділю.',
            'content_hash' => hash('sha256', 'newer'),
            'created_at' => now(),
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSeeInOrder(['Спочатку домовились про суботу.', 'Потім перенесли на неділю.'])
            ->assertSee('data-analysis-overlay', escape: false)
            ->assertSee('data-source-history', escape: false)
            ->assertSee('Гусь, розгреби все');

        $javascript = file_get_contents(resource_path('js/app.js'));
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertStringContainsString('setInterval(pollStatus, 2000)', $javascript);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('.goose-working', $css);
    }

    private function fakePng(string $name): UploadedFile
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZQmcAAAAASUVORK5CYII=',
            true,
        );

        return UploadedFile::fake()->createWithContent($name, $contents);
    }
}
