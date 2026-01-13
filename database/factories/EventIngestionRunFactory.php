<?php

namespace Database\Factories;

use App\Models\EventSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventIngestionRun>
 */
class EventIngestionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_source_id' => EventSource::factory(),
            'status' => fake()->randomElement(['queued', 'running', 'success', 'failed']),
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'finished_at' => now(),
            'items_found' => fake()->numberBetween(0, 25),
            'items_written' => fake()->numberBetween(0, 25),
            'error_class' => null,
            'error_message' => null,
        ];
    }
}
