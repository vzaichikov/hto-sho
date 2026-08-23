<?php

namespace Database\Factories;

use App\HarnessEntryKind;
use App\Models\HarnessEntry;
use App\Models\HarnessRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HarnessEntry>
 */
class HarnessEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'harness_run_id' => HarnessRun::factory(),
            'sequence' => fake()->unique()->numberBetween(1, 100000),
            'kind' => HarnessEntryKind::Action,
            'status' => 'completed',
            'title' => fake()->sentence(),
        ];
    }
}
