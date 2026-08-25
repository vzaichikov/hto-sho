<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\SilpoCartReset;
use App\SilpoCartResetStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SilpoCartReset>
 */
class SilpoCartResetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cartId = fake()->uuid();
        $emptyFingerprint = hash('sha256', json_encode([], JSON_THROW_ON_ERROR));

        return [
            'event_id' => Event::factory()->ready(),
            'user_id' => fn (array $attributes): int => Event::query()
                ->findOrFail($attributes['event_id'])
                ->user_id,
            'plan_state_version' => 1,
            'request_key' => fake()->unique()->sha256(),
            'status' => SilpoCartResetStatus::Cleared,
            'cart_id' => $cartId,
            'before_cart_fingerprint' => fake()->sha256(),
            'before_product_fingerprint' => fake()->sha256(),
            'empty_product_fingerprint' => $emptyFingerprint,
            'items_count' => 1,
            'total' => 100,
            'snapshot' => ['cart_id' => $cartId, 'cart' => ['shipments' => []]],
            'cleared_at' => now(),
        ];
    }
}
