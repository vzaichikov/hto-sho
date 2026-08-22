<?php

namespace Tests\Feature;

use App\EventSourceType;
use App\EventStatus;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_guest_is_redirected_to_the_passwordless_landing(): void
    {
        $this->get(route('events.index'))
            ->assertRedirect(route('landing'));
    }

    public function test_user_creates_an_empty_event_and_immediately_opens_workspace(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('events.store'));

        $event = Event::query()->whereBelongsTo($user)->sole();

        $response->assertRedirect(route('events.show', $event));
        $this->assertTrue($event->user->is($user));
        $this->assertMatchesRegularExpression('/^Подія · \d{2}\.\d{2} \d{2}:\d{2}$/', $event->title);
        $this->assertSame(EventStatus::Draft, $event->status);
        $this->assertSame(0, $event->state_version);
    }

    public function test_event_context_is_optional_and_editable(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'title' => 'Тестовий шашлик',
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('<title>Тестовий шашлик — Хто шо?</title>', escape: false)
            ->assertSee('Короткий опис')
            ->assertSee('Людей')
            ->assertSee('Бюджет, ₴')
            ->assertSee('Додати до історії')
            ->assertSee('Гусь, розгреби все')
            ->assertSee('Гусь чекає на новини')
            ->assertSee(asset('images/brand/goose-sho.png'), escape: false)
            ->assertDontSee('<select', escape: false);

        $this->actingAs($user)
            ->withSession(['success' => 'Назву події оновлено.'])
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('class="space-y-3 mt-4"', escape: false)
            ->assertSee('data-flash-messages', escape: false);

        $this->actingAs($user)
            ->patch(route('events.update', $event), [
                'title' => 'Шашлики в неділю',
                'description' => 'Зустрічаємось у парку після обіду.',
                'people_count' => 9,
                'budget_amount' => 3500,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $event->refresh();

        $this->assertSame('Шашлики в неділю', $event->title);
        $this->assertSame('Зустрічаємось у парку після обіду.', $event->description);
        $this->assertSame(9, $event->people_count);
        $this->assertSame('3500.00', $event->budget_amount);
    }

    public function test_ready_workspace_renders_the_context_harness_without_generating_a_product_plan(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create([
            'title' => 'Пікнік',
            'status' => EventStatus::Ready,
            'state_version' => 3,
            'evidence_version' => 3,
            'state_evidence_version' => 3,
            'state' => [
                'summary' => 'Зустріч для шести друзів.',
                'participants' => [[
                    'name' => 'Оля',
                    'status' => 'буде',
                    'preferences' => [],
                    'restrictions' => ['без мʼяса'],
                    'allergies' => [],
                    'brings' => ['плед'],
                    'source_ids' => [9],
                ]],
                'restrictions' => [[
                    'participant' => 'Оля',
                    'restriction' => 'без мʼяса',
                    'severity' => 'hard',
                    'source_ids' => [9],
                ]],
                'agreements' => [],
                'warnings' => [['message' => 'Уточніть напої для Тараса.', 'source_ids' => [9]]],
                'unresolved_questions' => [],
                'source_ids' => [9],
            ],
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Що Гусь зрозумів')
            ->assertSee('Оля')
            ->assertSee('без мʼяса')
            ->assertSee('Історія контексту')
            ->assertSee('Обережно')
            ->assertDontSee('Кошик Сільпо')
            ->assertDontSee('<select', escape: false)
            ->assertDontSee('type="checkbox"', escape: false);

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee('Пікнік')
            ->assertSee('Очікується людей');
    }

    public function test_user_cannot_view_or_change_another_users_event(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = Event::factory()->for($owner)->create();

        $this->actingAs($stranger)->get(route('events.show', $event))->assertForbidden();
        $this->actingAs($stranger)->get(route('events.status', $event))->assertForbidden();
        $this->actingAs($stranger)->patch(route('events.update', $event), ['title' => 'Чуже'])->assertForbidden();
        $this->actingAs($stranger)->delete(route('events.destroy', $event))->assertForbidden();

        $this->assertDatabaseHas('events', ['id' => $event->id, 'title' => $event->title]);
    }

    public function test_deleting_an_event_removes_database_sources_and_private_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $event = Event::factory()->for($user)->create();
        $path = "events/{$user->id}/{$event->id}/chat.png";
        Storage::disk('local')->put($path, 'private-image');
        $source = EventSource::factory()->for($event)->create([
            'type' => EventSourceType::Image,
            'text' => null,
            'file_path' => $path,
            'original_name' => 'chat.png',
            'mime_type' => 'image/png',
            'size' => 13,
            'upload_batch' => (string) Str::ulid(),
            'content_hash' => hash('sha256', 'private-image'),
        ]);

        $this->actingAs($user)
            ->delete(route('events.destroy', $event))
            ->assertRedirect(route('events.index'));

        $this->assertDatabaseMissing('events', ['id' => $event->id]);
        $this->assertDatabaseMissing('event_sources', ['id' => $source->id]);
        Storage::disk('local')->assertMissing($path);
    }
}
