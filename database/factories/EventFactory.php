<?php

namespace Database\Factories;

use App\CartSyncStatus;
use App\EventStatus;
use App\Models\Event;
use App\Models\User;
use App\PlanGenerationStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => 'Нова подія',
            'description' => null,
            'alcohol_planned' => false,
            'people_count' => null,
            'status' => EventStatus::Draft,
            'state_version' => 0,
            'evidence_version' => 0,
            'currency' => 'UAH',
            'cart_sync_status' => CartSyncStatus::NotSynced,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Ready,
            'state' => [
                'summary' => 'Вечірка для друзів',
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [],
                'source_ids' => [],
            ],
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'shopping_plan' => [
                'summary' => 'Усе потрібне для вечірки.',
                'serves' => 6,
                'items' => [],
                'warnings' => [],
                'unanswered_question_keys' => [],
            ],
            'plan_state_version' => 1,
            'plan_generation_status' => PlanGenerationStatus::Ready,
        ]);
    }
}
