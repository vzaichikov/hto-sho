<?php

namespace Database\Factories;

use App\HarnessRunStatus;
use App\HarnessRunType;
use App\Models\Event;
use App\Models\HarnessRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HarnessRun>
 */
class HarnessRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'type' => fake()->randomElement(HarnessRunType::cases()),
            'status' => HarnessRunStatus::Completed,
            'correlation_id' => fake()->uuid(),
            'metadata' => [],
            'next_sequence' => 1,
            'started_at' => now()->subSecond(),
            'finished_at' => now(),
        ];
    }
}
