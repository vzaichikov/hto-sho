<?php

namespace Tests\Feature;

use App\EventAnalysisStage;
use App\EventSourceType;
use App\EventStatus;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\PlanGenerationStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_event_list_routes_creation_through_the_wizard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('events.index'))
            ->assertOk()
            ->assertSee(route('events.create'), escape: false)
            ->assertSee('Назвіть задум у двох коротких кроках')
            ->assertDontSee('action="'.route('events.store').'"', escape: false);

        $this->assertSame(0, Event::query()->whereBelongsTo($user)->count());
    }

    public function test_event_context_is_optional_and_editable(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        config()->set([
            'services.ai.provider' => 'openai',
            'services.ai.model' => 'gpt-5.4-mini',
            'services.ai.api_key' => 'test-key',
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response([
                'output' => [[
                    'content' => [[
                        'type' => 'output_text',
                        'text' => json_encode(['accepted' => true, 'reason' => 'accepted']),
                    ]],
                ]],
            ]),
        ]);
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
            ->assertSee('Додати й оновити')
            ->assertSee('Підкиньте новий контекст')
            ->assertSeeInOrder(['Контекст', 'Питання', 'Список', 'Сільпо'])
            ->assertSee('aria-current="step"', escape: false)
            ->assertSee(asset('images/brand/goose-sho.png'), escape: false)
            ->assertDontSee('Гусь, розгреби все')
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
        $this->assertSame(1, $event->evidence_version);
        Queue::assertPushed(SummarizeEventContextJob::class, 1);
    }

    public function test_ready_workspace_renders_all_four_human_facing_steps(): void
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
                    'status' => 'confirmed',
                    'preferences' => [],
                    'restrictions' => ['без мʼяса'],
                    'allergies' => [],
                    'brings' => ['плед'],
                    'source_ids' => [],
                ]],
                'restrictions' => [[
                    'participant' => 'Оля',
                    'restriction' => 'без мʼяса',
                    'severity' => 'hard',
                    'source_ids' => [],
                ]],
                'agreements' => [],
                'warnings' => [['message' => 'Уточніть напої для Тараса.', 'source_ids' => []]],
                'unresolved_questions' => [[
                    'key' => 'q_alcohol',
                    'question' => 'Чи потрібен алкоголь?',
                    'impact' => 'Відповідь змінить склад напоїв.',
                    'blocking' => false,
                    'options' => [[
                        'label' => 'Не додавати',
                        'description' => 'Безпечний варіант до уточнення.',
                        'recommended' => true,
                    ], [
                        'label' => 'Додати пиво',
                        'description' => 'Якщо компанія це підтвердила.',
                        'recommended' => false,
                    ]],
                    'source_ids' => [],
                ]],
                'source_ids' => [],
            ],
            'shopping_plan' => [
                'summary' => 'Їжа й напої для пікніка.',
                'serves' => 6,
                'items' => [[
                    'name' => 'Вода питна',
                    'category' => 'water',
                    'quantity' => 6,
                    'unit' => 'л',
                    'note' => 'По літру на людину.',
                ]],
                'warnings' => [],
                'unanswered_question_keys' => ['q_alcohol'],
            ],
            'plan_state_version' => 3,
            'plan_generation_status' => PlanGenerationStatus::Ready,
        ]);

        $this->actingAs($user)
            ->get(route('events.show', $event))
            ->assertOk()
            ->assertSee('Що Гусь зрозумів')
            ->assertSee('Оля')
            ->assertSee('без мʼяса')
            ->assertSee('На що звернути увагу')
            ->assertDontSee('Кошик Сільпо')
            ->assertDontSee('OCR')
            ->assertDontSee('SHA-кеш')
            ->assertDontSee('source #')
            ->assertDontSee('full summary')
            ->assertDontSee('фоновий job')
            ->assertDontSee('<select', escape: false)
            ->assertDontSee('type="checkbox"', escape: false);

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $event, 'tab' => 'questions']))
            ->assertOk()
            ->assertSee('Чи потрібен алкоголь?')
            ->assertSee('Гусь радить')
            ->assertSee('Своя відповідь');

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $event, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Загальний список')
            ->assertSee('Вода питна')
            ->assertSee('Розраховано на 6 людей')
            ->assertSee('Внести корективу')
            ->assertSee('Передати корективу Гусю')
            ->assertSee('Відправити Гуся в Сільпо')
            ->assertSee('Відправити Гуся в Сільпо?')
            ->assertSee('Гусь піде збирати кошик. Це займе деякий час.')
            ->assertSee('Нехай іде')
            ->assertSee('method="dialog"', escape: false)
            ->assertDontSee('cart-sync');

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $event, 'tab' => 'silpo']))
            ->assertOk()
            ->assertSee('Кошик Сільпо')
            ->assertSee('Справжній кошик')
            ->assertSee('Гусь ще не ходив між прилавками для цієї події.')
            ->assertSee('Відправити Гуся в Сільпо')
            ->assertSee('data-silpo-dialog', escape: false)
            ->assertDontSee('Вода питна')
            ->assertDontSee('фоновий job');

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

    public function test_tabs_explain_empty_loading_stale_and_error_states_without_technical_copy(): void
    {
        $user = User::factory()->create();
        $empty = Event::factory()->for($user)->create();

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $empty, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Без контексту навіть Гусь не вгадає')
            ->assertSee('Додати контекст');

        $loading = Event::factory()->for($user)->create([
            'status' => EventStatus::Processing,
            'state' => $this->eventState(),
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'plan_generation_status' => PlanGenerationStatus::Processing,
        ]);

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $loading, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Гусь уже рахує, скільки всього треба');

        $stale = Event::factory()->for($user)->create([
            'status' => EventStatus::Ready,
            'state' => $this->eventState(),
            'state_version' => 1,
            'evidence_version' => 2,
            'state_evidence_version' => 1,
            'shopping_plan' => $this->eventPlan(),
            'plan_state_version' => 1,
            'plan_generation_status' => PlanGenerationStatus::Ready,
        ]);

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $stale, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Гусь почув нове й уже перераховує')
            ->assertSee('Внести корективу')
            ->assertSee('Відправити Гуся в Сільпо')
            ->assertSee('disabled', escape: false);

        $summaryError = Event::factory()->for($user)->create([
            'status' => EventStatus::Failed,
            'analysis_stage' => EventAnalysisStage::Failed,
            'analysis_error' => 'Не вдалося перечитати матеріали.',
        ]);

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $summaryError, 'tab' => 'context']))
            ->assertOk()
            ->assertSee('Гусь перечепився й не зміг оновити картину')
            ->assertSee('Гусь, спробуй ще раз');

        $planError = Event::factory()->for($user)->create([
            'status' => EventStatus::Ready,
            'state' => $this->eventState(),
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'plan_generation_status' => PlanGenerationStatus::Failed,
            'plan_generation_error' => 'Не вдалося скласти список.',
        ]);

        $this->actingAs($user)
            ->get(route('events.show', ['event' => $planError, 'tab' => 'plan']))
            ->assertOk()
            ->assertSee('Гусь перечепився й не склав список')
            ->assertSee('Гусь, спробуй ще раз')
            ->assertDontSee('OCR')
            ->assertDontSee('job')
            ->assertDontSee('task')
            ->assertDontSee('source #');
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

    /** @return array<string, mixed> */
    private function eventState(): array
    {
        return [
            'summary' => 'Зустріч для друзів.',
            'participants' => [],
            'restrictions' => [],
            'agreements' => [],
            'warnings' => [],
            'unresolved_questions' => [],
            'source_ids' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function eventPlan(): array
    {
        return [
            'summary' => 'Список для зустрічі.',
            'serves' => 6,
            'items' => [[
                'name' => 'Вода',
                'category' => 'water',
                'quantity' => 6,
                'unit' => 'л',
                'note' => '',
            ]],
            'warnings' => [],
            'unanswered_question_keys' => [],
        ];
    }
}
