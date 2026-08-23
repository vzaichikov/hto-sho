<?php

namespace Database\Factories;

use App\Models\EventCartRun;
use App\Models\EventCartRunStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventCartRunStep>
 */
class EventCartRunStepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_cart_run_id' => EventCartRun::factory(),
            'sequence' => 1,
            'kind' => 'planning',
            'message' => 'Гусь розкладає список по поличках.',
            'context' => [],
        ];
    }
}
