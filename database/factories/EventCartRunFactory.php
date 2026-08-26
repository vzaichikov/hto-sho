<?php

namespace Database\Factories;

use App\CartHarnessMode;
use App\CartRunMode;
use App\CartRunPhase;
use App\CartRunStatus;
use App\Models\Event;
use App\Models\EventCartRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventCartRun>
 */
class EventCartRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cartId = fake()->uuid();

        return [
            'event_id' => Event::factory()->ready(),
            'mode' => CartRunMode::Assisted,
            'harness_mode' => CartHarnessMode::Orchestrated,
            'status' => CartRunStatus::Running,
            'phase' => CartRunPhase::Preparing,
            'plan_state_version' => 1,
            'cursor' => 0,
            'cart_id' => $cartId,
            'delivery_fingerprint' => fake()->sha256(),
            'cart_context' => [
                'cart_id' => $cartId,
                'delivery_type' => 'DeliveryHome',
                'branch_id' => fake()->uuid(),
                'company_id' => fake()->uuid(),
                'slot_start' => now()->addDay()->toISOString(),
                'slot_end' => now()->addDay()->addHour()->toISOString(),
                'items' => [],
                'validations' => [],
            ],
            'state' => [],
            'staged_items' => [],
            'warnings' => [],
            'started_at' => now(),
        ];
    }
}
