<?php

namespace Tests\Feature;

use App\EventStatus;
use App\Jobs\BuildEventShoppingPlanJob;
use App\Models\Event;
use App\Models\EventSource;
use App\Models\User;
use App\PlanGenerationStatus;
use App\Services\ContextAnalysisService;
use App\Services\HarnessRecorder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use UnexpectedValueException;

class EventShoppingPlanJobTest extends TestCase
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

    public function test_current_state_builds_a_safe_generic_plan(): void
    {
        $event = $this->currentEvent();
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($this->planPayload())),
        ]);

        $job = new BuildEventShoppingPlanJob($event->id, 1);
        $job->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();

        $this->assertSame($event->id.':1', $job->uniqueId());
        $this->assertSame(PlanGenerationStatus::Ready, $event->plan_generation_status);
        $this->assertSame(1, $event->plan_state_version);
        $this->assertSame(8, $event->shopping_plan['serves']);
        $this->assertSame(['water', 'soft_drinks', 'food'], collect($event->shopping_plan['items'])->pluck('category')->all());
        $this->assertNotContains('alcohol', collect($event->shopping_plan['items'])->pluck('category'));
        $this->assertSame(['q_alcohol'], $event->shopping_plan['unanswered_question_keys']);

        Http::assertSent(function (Request $request): bool {
            $prompt = $request['input'][0]['content'][0]['text'];

            return str_contains($prompt, 'питну воду та безалкогольні напої')
                && str_contains($prompt, 'алкоголь додавай лише коли alcohol_planned=true')
                && str_contains($prompt, '"alcohol_planned": false')
                && str_contains($prompt, 'не вказуй SKU, бренди Сільпо, ціни')
                && str_contains($prompt, 'не дублюй це в покупках')
                && str_contains($prompt, 'алергії та жорсткі обмеження — критичні факти');
        });
    }

    public function test_result_for_an_old_state_is_discarded_without_erasing_the_previous_plan(): void
    {
        $oldPlan = $this->planPayload('Попередній правильний список.');
        $event = $this->currentEvent([
            'shopping_plan' => $oldPlan,
            'plan_state_version' => 0,
        ]);
        Http::fake(function () use ($event) {
            Event::query()->whereKey($event->id)->update([
                'state_version' => 2,
                'plan_generation_status' => PlanGenerationStatus::Pending,
            ]);

            return Http::response($this->openAiResponse($this->planPayload('Запізнілий список.')));
        });

        (new BuildEventShoppingPlanJob($event->id, 1))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame('Попередній правильний список.', $event->shopping_plan['summary']);
        $this->assertSame(0, $event->plan_state_version);
        $this->assertSame(PlanGenerationStatus::Pending, $event->plan_generation_status);
    }

    public function test_unanswered_alcohol_question_cannot_produce_alcohol_items(): void
    {
        $event = $this->currentEvent();
        $unsafePlan = $this->planPayload();
        $unsafePlan['items'][] = [
            'name' => 'Пиво',
            'category' => 'alcohol',
            'quantity' => 8,
            'unit' => 'пляшок',
            'note' => '',
        ];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($unsafePlan)),
        ]);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('assumes alcohol');

        (new BuildEventShoppingPlanJob($event->id, 1))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));
    }

    public function test_creation_confirmation_allows_alcohol_without_a_redundant_confirmation_answer(): void
    {
        $event = $this->currentEvent(['alcohol_planned' => true]);
        $confirmedPlan = $this->planPayload();
        $confirmedPlan['items'][] = [
            'name' => 'Світле пиво',
            'category' => 'alcohol',
            'quantity' => 12,
            'unit' => 'банок',
            'note' => 'Явно заплановано організатором.',
        ];
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($confirmedPlan)),
        ]);

        (new BuildEventShoppingPlanJob($event->id, 1))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        $event->refresh();
        $this->assertSame(PlanGenerationStatus::Ready, $event->plan_generation_status);
        $this->assertContains('alcohol', collect($event->shopping_plan['items'])->pluck('category'));
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request['input'][0]['content'][0]['text'],
            '"alcohol_planned": true',
        ));
    }

    public function test_plan_correction_uses_its_immutable_reference_plan(): void
    {
        $basePlan = $this->planPayload('Список, який бачив організатор.');
        $laterPlan = $this->planPayload('Пізніший список не є базою цієї корективи.');
        $event = $this->currentEvent([
            'state_version' => 3,
            'evidence_version' => 3,
            'state_evidence_version' => 3,
            'shopping_plan' => $laterPlan,
            'plan_state_version' => 2,
        ]);
        EventSource::factory()->for($event)->create([
            'origin' => 'plan_correction',
            'text' => 'Води вдвічі менше.',
            'metadata' => [
                'base_plan_state_version' => 1,
                'base_plan' => $basePlan,
            ],
        ]);
        Http::fake([
            'https://api.openai.com/v1/responses' => Http::response($this->openAiResponse($this->planPayload('Оновлений список.'))),
        ]);

        (new BuildEventShoppingPlanJob($event->id, 3))
            ->handle($this->app->make(ContextAnalysisService::class), $this->app->make(HarnessRecorder::class));

        Http::assertSent(function (Request $request): bool {
            $prompt = $request['input'][0]['content'][0]['text'];

            return str_contains($prompt, 'Води вдвічі менше.')
                && str_contains($prompt, 'Список, який бачив організатор.')
                && ! str_contains($prompt, 'Пізніший список не є базою цієї корективи.')
                && str_contains($prompt, 'base_plan — лише незмінний довідковий знімок')
                && str_contains($prompt, 'застосуй кожну відносну корективу один раз');
        });
    }

    public function test_failure_keeps_the_summary_and_previous_plan_visible(): void
    {
        $event = $this->currentEvent([
            'shopping_plan' => $this->planPayload('Старий список.'),
            'plan_state_version' => 0,
        ]);

        (new BuildEventShoppingPlanJob($event->id, 1))->failed(new \RuntimeException('Тимчасова помилка'));

        $event->refresh();
        $this->assertSame('Пікнік для восьми людей.', $event->state['summary']);
        $this->assertSame('Старий список.', $event->shopping_plan['summary']);
        $this->assertSame(PlanGenerationStatus::Failed, $event->plan_generation_status);
        $this->assertSame('Тимчасова помилка', $event->plan_generation_error);
    }

    public function test_cart_sync_placeholder_route_is_not_available(): void
    {
        $owner = User::factory()->create();
        $event = $this->currentEvent([
            'user_id' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->post("/events/{$event->id}/cart-sync")
            ->assertNotFound();

        $event->refresh();
        $this->assertNull($event->silpo_cart_id);
        $this->assertNull($event->cart_synced_at);
        Http::assertNothingSent();
    }

    /** @param array<string, mixed> $overrides */
    private function currentEvent(array $overrides = []): Event
    {
        return Event::factory()->create([
            'status' => EventStatus::Ready,
            'title' => 'Пікнік',
            'description' => 'Пікнік на природі.',
            'people_count' => 8,
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'state' => [
                'summary' => 'Пікнік для восьми людей.',
                'participants' => [[
                    'name' => 'Оля',
                    'status' => 'confirmed',
                    'preferences' => [],
                    'restrictions' => [],
                    'allergies' => [],
                    'brings' => ['2 л лимонаду'],
                    'source_ids' => [],
                ]],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [[
                    'key' => 'q_alcohol',
                    'question' => 'Чи потрібен алкоголь?',
                    'impact' => 'Відповідь змінить склад напоїв.',
                    'blocking' => false,
                    'options' => [],
                    'source_ids' => [],
                ]],
                'source_ids' => [],
            ],
            'plan_generation_status' => PlanGenerationStatus::Pending,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function planPayload(string $summary = 'Базовий список без непідтвердженого алкоголю.'): array
    {
        return [
            'summary' => $summary,
            'serves' => 8,
            'items' => [[
                'name' => 'Вода питна',
                'category' => 'water',
                'quantity' => 8,
                'unit' => 'л',
                'note' => 'По літру на людину.',
            ], [
                'name' => 'Сік',
                'category' => 'soft_drinks',
                'quantity' => 3,
                'unit' => 'л',
                'note' => 'Лимонад уже приносить гостя.',
            ], [
                'name' => 'Овочі',
                'category' => 'food',
                'quantity' => 2,
                'unit' => 'кг',
                'note' => 'Загальна закуска.',
            ]],
            'warnings' => ['Алкоголь не додано до уточнення.'],
            'unanswered_question_keys' => ['q_alcohol'],
        ];
    }

    /** @param array<string, mixed> $payload */
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
