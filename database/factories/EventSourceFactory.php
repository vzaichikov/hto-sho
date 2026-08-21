<?php

namespace Database\Factories;

use App\EventSourceStatus;
use App\EventSourceType;
use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventSource>
 */
class EventSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = fake()->sentence();

        return [
            'event_id' => Event::factory(),
            'type' => EventSourceType::Text,
            'text' => $text,
            'upload_batch' => (string) Str::ulid(),
            'position' => 0,
            'content_hash' => hash('sha256', $text),
            'status' => EventSourceStatus::Pending,
        ];
    }
}
