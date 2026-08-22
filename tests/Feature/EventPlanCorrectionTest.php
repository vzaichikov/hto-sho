<?php

namespace Tests\Feature;

use App\CartSyncStatus;
use App\EventSourceInclusion;
use App\EventSourceStatus;
use App\EventSourceType;
use App\EventStatus;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\User;
use App\PlanGenerationStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventPlanCorrectionTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_owner_can_add_a_correction_to_the_current_plan(): void
    {
        $owner = User::factory()->create();
        $event = $this->currentEvent($owner, [
            'cart_sync_status' => CartSyncStatus::Synced,
            'cart_synced_state_version' => 2,
            'cart_synced_at' => now(),
        ]);

        $this->actingAs($owner)
            ->post(route('events.plan-corrections.store', $event), [
                'plan_state_version' => 2,
                'correction' => '  Води вдвічі менше.  ',
            ])
            ->assertRedirect(route('events.show', ['event' => $event, 'tab' => 'plan']))
            ->assertSessionHas('success', 'Гусь почув корективу й уже перебудовує список.');

        $event->refresh();
        $correction = $event->sources()->sole();

        $this->assertSame(EventSourceType::Text, $correction->type);
        $this->assertSame('plan_correction', $correction->origin);
        $this->assertSame('Води вдвічі менше.', $correction->text);
        $this->assertSame(EventSourceStatus::Processed, $correction->status);
        $this->assertSame(EventSourceInclusion::Included, $correction->inclusion);
        $this->assertSame(2, $correction->metadata['base_plan_state_version']);
        $this->assertSame($this->plan(), $correction->metadata['base_plan']);
        $this->assertSame(3, $event->evidence_version);
        $this->assertSame(2, $event->state_version);
        $this->assertSame(2, $event->plan_state_version);
        $this->assertSame($this->plan(), $event->shopping_plan);
        $this->assertSame(EventStatus::Processing, $event->status);
        $this->assertSame(CartSyncStatus::Stale, $event->cart_sync_status);
        $this->assertFalse($event->isPlanCurrent());
        Queue::assertPushed(SummarizeEventContextJob::class, 1);

        $this->actingAs($owner)
            ->get(route('events.show', ['event' => $event, 'tab' => 'context']))
            ->assertOk()
            ->assertSee('Коректива до списку')
            ->assertSee('Води вдвічі менше.');
    }

    public function test_repeating_the_same_correction_does_not_duplicate_evidence(): void
    {
        $owner = User::factory()->create();
        $event = $this->currentEvent($owner);
        $payload = [
            'plan_state_version' => 2,
            'correction' => 'Прибрати одноразовий посуд.',
        ];

        $this->actingAs($owner)->post(route('events.plan-corrections.store', $event), $payload);
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('events.plan-corrections.store', $event), $payload)
            ->assertRedirect(route('events.show', ['event' => $event, 'tab' => 'plan']))
            ->assertSessionHas('info', 'Цю корективу Гусь уже почув.');

        $this->assertSame(1, $event->sources()->count());
        $this->assertSame(3, $event->refresh()->evidence_version);
        Queue::assertNothingPushed();
    }

    public function test_stale_missing_and_foreign_plan_corrections_are_rejected(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->currentEvent($owner);

        $this->actingAs($owner)
            ->post(route('events.plan-corrections.store', $event), [
                'plan_state_version' => 1,
                'correction' => 'Додати більше фруктів.',
            ])
            ->assertSessionHasErrors('plan_state_version');

        $event->update(['plan_generation_status' => PlanGenerationStatus::Pending]);

        $this->actingAs($owner)
            ->post(route('events.plan-corrections.store', $event), [
                'plan_state_version' => 2,
                'correction' => 'Додати більше фруктів.',
            ])
            ->assertSessionHasErrors('plan_state_version');

        $this->actingAs($stranger)
            ->post(route('events.plan-corrections.store', $event), [
                'plan_state_version' => 2,
                'correction' => 'Додати більше фруктів.',
            ])
            ->assertForbidden();

        $this->assertSame(0, $event->sources()->count());
        Queue::assertNothingPushed();
    }

    /** @param array<string, mixed> $overrides */
    private function currentEvent(User $owner, array $overrides = []): Event
    {
        return Event::factory()->for($owner)->create([
            'status' => EventStatus::Ready,
            'state' => [
                'summary' => 'Пікнік для восьми людей.',
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [],
                'source_ids' => [],
            ],
            'state_version' => 2,
            'evidence_version' => 2,
            'state_evidence_version' => 2,
            'shopping_plan' => $this->plan(),
            'plan_state_version' => 2,
            'plan_generation_status' => PlanGenerationStatus::Ready,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return [
            'summary' => 'Базовий список.',
            'serves' => 8,
            'items' => [[
                'name' => 'Вода питна',
                'category' => 'water',
                'quantity' => 8,
                'unit' => 'л',
                'note' => 'По літру на людину.',
            ]],
            'warnings' => [],
            'unanswered_question_keys' => [],
        ];
    }
}
