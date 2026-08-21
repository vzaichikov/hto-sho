<?php

namespace Database\Factories;

use App\Models\SilpoConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SilpoConnection>
 */
class SilpoConnectionFactory extends Factory
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
            'client_id' => fake()->uuid(),
            'client_secret' => fake()->sha256(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'token_type' => 'Bearer',
            'scope' => 'mcp:use',
            'expires_at' => now()->addHour(),
            'profile_snapshot' => [
                'id' => fake()->uuid(),
                'name' => fake()->name(),
            ],
            'profile_synced_at' => now(),
            'last_verified_at' => now(),
        ];
    }
}
