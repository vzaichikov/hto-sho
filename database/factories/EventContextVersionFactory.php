<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventContextVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventContextVersion>
 */
class EventContextVersionFactory extends Factory
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
            'state_version' => 1,
            'evidence_version' => 1,
            'state' => [
                'summary' => fake()->sentence(),
                'participants' => [],
                'restrictions' => [],
                'agreements' => [],
                'warnings' => [],
                'unresolved_questions' => [],
                'source_ids' => [],
            ],
        ];
    }
}
