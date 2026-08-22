<?php

namespace Database\Factories;

use App\ImageClassification;
use App\ImageExtractionStatus;
use App\Models\ImageExtraction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageExtraction>
 */
class ImageExtractionFactory extends Factory
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
            'content_hash' => hash('sha256', fake()->uuid()),
            'status' => ImageExtractionStatus::Processed,
            'classification' => ImageClassification::ChatScreenshot,
            'ocr_text' => fake()->sentence(),
            'message_timeline' => [],
            'source_summary' => fake()->sentence(),
            'processed_at' => now(),
        ];
    }
}
