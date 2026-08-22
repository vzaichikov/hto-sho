<?php

namespace Database\Factories;

use App\CartSyncStatus;
use App\EventStatus;
use App\Models\Event;
use App\Models\User;
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
                'participants_count' => 6,
                'participants' => [],
                'shopping_needs' => [],
                'warnings' => [],
            ],
            'state_version' => 1,
            'evidence_version' => 1,
            'state_evidence_version' => 1,
            'shopping_plan' => [
                'items' => [],
                'unresolved' => [],
            ],
            'plan_state_version' => 1,
        ]);
    }
}
