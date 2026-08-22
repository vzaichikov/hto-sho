<?php

namespace Tests\Feature;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\Jobs\ProcessImageExtractionJob;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\PlanGenerationStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventSourceTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_text_only_source_is_trimmed_stored_and_starts_analysis_automatically(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), ['text' => '  Оля не їсть мʼясо.  '])
            ->assertRedirect()
            ->assertSessionHas('success');

        $source = EventSource::query()->whereBelongsTo($event)->sole();

        $this->assertSame(EventSourceType::Text, $source->type);
        $this->assertSame('Оля не їсть мʼясо.', $source->text);
        $this->assertSame(EventSourceStatus::Processed, $source->status);
        $this->assertSame(EventSourceInclusion::Included, $source->inclusion);
        $this->assertSame(EventStatus::Processing, $event->refresh()->status);
        $this->assertSame(1, $event->evidence_version);
        $this->assertNotNull($event->last_source_at);
        Queue::assertPushed(SummarizeEventContextJob::class, 1);
    }

    public function test_image_only_and_mixed_batches_are_stored_on_private_disk(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $imageOnlyEvent = Event::factory()->for($user)->create();

        $this->actingAs($user)->post(route('events.sources.store', $imageOnlyEvent), [
            'images' => [UploadedFile::fake()->image('chat-one.png')->size(250)],
        ])->assertRedirect()->assertSessionHas('success');

        $imageSource = $imageOnlyEvent->sources()->sole();
        $this->assertSame(EventSourceType::Image, $imageSource->type);
        $this->assertStringStartsWith("events/{$user->id}/{$imageOnlyEvent->id}/", $imageSource->file_path);
        Storage::disk('local')->assertExists($imageSource->file_path);
        Queue::assertPushed(ProcessImageExtractionJob::class, 1);

        $mixedEvent = Event::factory()->for($user)->create();
        $this->actingAs($user)->post(route('events.sources.store', $mixedEvent), [
            'text' => 'Тарас бере вугілля.',
            'images' => [UploadedFile::fake()->image('chat-two.jpg')->size(300)],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame(2, $mixedEvent->sources()->count());
        $this->assertSame(1, $mixedEvent->sources()->where('type', EventSourceType::Text)->count());
        $this->assertSame(1, $mixedEvent->sources()->where('type', EventSourceType::Image)->count());
    }

    public function test_duplicate_content_is_not_added_twice(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $payload = ['text' => 'Лимонад без цукру.'];

        $this->actingAs($user)->post(route('events.sources.store', $event), $payload);
        $this->actingAs($user)
            ->post(route('events.sources.store', $event), $payload)
            ->assertRedirect()
            ->assertSessionHas('info', 'Ці матеріали Гусь уже бачив.');

        $this->assertSame(1, $event->sources()->count());
    }

    public function test_text_source_does_not_enter_the_image_retry_flow(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $text = 'Повторіть обробку цього повідомлення.';
        $source = EventSource::factory()->for($event)->create([
            'text' => $text,
            'content_hash' => hash('sha256', $text),
            'status' => EventSourceStatus::Processed,
            'processing_error' => 'Temporary failure',
            'processed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), ['text' => $text])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame(1, $event->sources()->count());
        $this->assertSame(EventSourceStatus::Processed, $source->refresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_invalid_mime_oversized_file_and_empty_batch_are_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), [
                'images' => [UploadedFile::fake()->create('chat.pdf', 100, 'application/pdf')],
            ])
            ->assertSessionHasErrors('images.0');

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), [
                'images' => [UploadedFile::fake()->image('large.png')->size(9000)],
            ])
            ->assertSessionHasErrors('images.0');

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), [])
            ->assertSessionHasErrors(['text', 'images']);

        $this->assertSame(0, $event->sources()->count());
    }

    public function test_new_source_makes_synced_cart_stale_without_changing_versions(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'status' => EventStatus::Ready,
            'state' => ['summary' => 'Готовий стан'],
            'state_version' => 4,
            'evidence_version' => 4,
            'state_evidence_version' => 4,
            'shopping_plan' => ['items' => [['name' => 'Вода']]],
            'plan_state_version' => 4,
            'plan_generation_status' => PlanGenerationStatus::Ready,
            'cart_sync_status' => CartSyncStatus::Synced,
            'cart_synced_state_version' => 4,
            'cart_synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('events.sources.store', $event), ['text' => 'Марина не прийде.'])
            ->assertRedirect();

        $event->refresh();

        $this->assertSame(EventStatus::Processing, $event->status);
        $this->assertSame(CartSyncStatus::Stale, $event->cart_sync_status);
        $this->assertSame(5, $event->evidence_version);
        $this->assertSame(4, $event->state_version);
        $this->assertSame(4, $event->plan_state_version);
        $this->assertSame(4, $event->cart_synced_state_version);
        $this->assertFalse($event->isPlanCurrent());
        $this->assertFalse($event->isCartCurrent());
    }

    public function test_private_image_can_only_be_read_by_event_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($owner)->post(route('events.sources.store', $event), [
            'images' => [UploadedFile::fake()->image('private.png')],
        ]);
        $source = $event->sources()->sole();

        $this->actingAs($owner)
            ->get(route('events.sources.show', [$event, $source]))
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($stranger)
            ->get(route('events.sources.show', [$event, $source]))
            ->assertForbidden();
    }

    public function test_status_polling_is_owner_scoped_and_reports_versions(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create([
            'status' => EventStatus::Processing,
            'state_version' => 2,
            'evidence_version' => 3,
        ]);
        EventSource::factory()->for($event)->create(['status' => EventSourceStatus::Pending]);

        $this->actingAs($owner)
            ->getJson(route('events.status', $event))
            ->assertOk()
            ->assertJsonPath('status', 'processing')
            ->assertJsonPath('state_version', 2)
            ->assertJsonPath('sources_count', 1)
            ->assertJsonPath('source_counts.pending', 1)
            ->assertJsonPath('evidence_version', 3)
            ->assertJsonPath('has_unanalyzed_changes', true)
            ->assertJsonPath('plan_current', false)
            ->assertJsonPath('cart_current', false);

        $this->actingAs($stranger)
            ->getJson(route('events.status', $event))
            ->assertForbidden();
    }
}
