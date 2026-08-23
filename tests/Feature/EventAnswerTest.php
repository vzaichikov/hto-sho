<?php

namespace Tests\Feature;

use App\EventStatus;
use App\Jobs\SummarizeEventContextJob;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EventAnswerTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_owner_can_answer_several_current_questions_in_one_request(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventWithQuestions($owner);

        $this->actingAs($owner)
            ->post(route('events.answers.store', $event), [
                'state_version' => 1,
                'answers' => [[
                    'question_key' => 'q_people',
                    'answer' => 'Нас буде 8',
                ], [
                    'question_key' => 'q_alcohol',
                    'answer' => 'Алкоголь не потрібен',
                ]],
            ])
            ->assertRedirect(route('events.show', ['event' => $event, 'tab' => 'questions']))
            ->assertSessionHas('success');

        $event->refresh();

        $this->assertSame(EventStatus::Processing, $event->status);
        $this->assertSame(3, $event->evidence_version);
        $this->assertCount(2, $event->sources);
        $this->assertSame(['question_answer'], $event->sources->pluck('origin')->unique()->values()->all());
        $firstAnswer = $event->sources()->where('position', 0)->sole();
        $this->assertSame('q_people', $firstAnswer->metadata['question_key']);
        $this->assertStringContainsString('Нас буде 8', $firstAnswer->text);
        Queue::assertPushed(SummarizeEventContextJob::class, 1);
    }

    public function test_repeating_the_same_answer_does_not_duplicate_evidence(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventWithQuestions($owner);
        $payload = [
            'state_version' => 1,
            'answers' => [[
                'question_key' => 'q_people',
                'answer' => 'Нас буде 8',
            ]],
        ];

        $this->actingAs($owner)->post(route('events.answers.store', $event), $payload);
        Queue::fake();

        $this->actingAs($owner)
            ->post(route('events.answers.store', $event), $payload)
            ->assertRedirect()
            ->assertSessionHas('info', 'Ці відповіді Гусь уже почув.');

        $this->assertSame(1, $event->sources()->count());
        $this->assertSame(2, $event->refresh()->evidence_version);
        Queue::assertNothingPushed();
    }

    public function test_answered_question_disappears_immediately_and_badge_counts_only_open_questions(): void
    {
        $owner = User::factory()->create();
        $event = $this->eventWithQuestions($owner);

        $this->actingAs($owner)->post(route('events.answers.store', $event), [
            'state_version' => 1,
            'answers' => [[
                'question_key' => 'q_people',
                'answer' => 'Нас буде 8',
            ]],
        ]);

        $this->actingAs($owner)
            ->get(route('events.show', ['event' => $event, 'tab' => 'questions']))
            ->assertOk()
            ->assertDontSee('Скільки людей буде?')
            ->assertSee('Чи потрібен алкоголь?')
            ->assertSee('Невирішених питань: 1');
    }

    public function test_old_unknown_and_foreign_questions_are_rejected(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $event = $this->eventWithQuestions($owner);

        $this->actingAs($owner)
            ->post(route('events.answers.store', $event), [
                'state_version' => 0,
                'answers' => [[
                    'question_key' => 'q_people',
                    'answer' => '8',
                ]],
            ])
            ->assertSessionHasErrors('state_version');

        $this->actingAs($owner)
            ->post(route('events.answers.store', $event), [
                'state_version' => 1,
                'answers' => [[
                    'question_key' => 'q_not_here',
                    'answer' => 'Щось',
                ]],
            ])
            ->assertSessionHasErrors('answers');

        $this->actingAs($stranger)
            ->post(route('events.answers.store', $event), [
                'state_version' => 1,
                'answers' => [[
                    'question_key' => 'q_people',
                    'answer' => '8',
                ]],
            ])
            ->assertForbidden();

        $this->assertSame(0, $event->sources()->count());
    }

    private function eventWithQuestions(User $owner): Event
    {
        return Event::factory()->for($owner)->create([
            'status' => EventStatus::Ready,
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'state' => [
                'summary' => 'Пікнік без уточненої кількості та напоїв.',
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [
                    $this->question('q_people', 'Скільки людей буде?', true),
                    $this->question('q_alcohol', 'Чи потрібен алкоголь?', false),
                ],
                'source_ids' => [],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function question(string $key, string $question, bool $blocking): array
    {
        return [
            'key' => $key,
            'question' => $question,
            'impact' => 'Відповідь змінює кількості та склад списку.',
            'blocking' => $blocking,
            'options' => [[
                'label' => 'Уточнити',
                'description' => 'Найбезпечніший варіант.',
                'recommended' => true,
            ], [
                'label' => 'Не враховувати',
                'description' => 'Не додавати нічого до уточнення.',
                'recommended' => false,
            ]],
            'source_ids' => [],
        ];
    }
}
