<?php

namespace Database\Factories;

use App\Models\ChatSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSourceIngestionRun>
 */
class ChatSourceIngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_source_id' => ChatSource::factory(),
            'status' => fake()->randomElement(['queued', 'running', 'success', 'failed']),
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'finished_at' => now(),
            'pages_found' => fake()->numberBetween(0, 25),
            'pages_changed' => fake()->numberBetween(0, 25),
            'pages_embedded' => fake()->numberBetween(0, 25),
            'error_class' => null,
            'error_message' => null,
        ];
    }
}
