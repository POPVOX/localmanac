<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventSource>
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
        return [
            'city_id' => City::factory(),
            'name' => fake()->words(2, true),
            'source_type' => fake()->randomElement(['ics', 'rss', 'json', 'html']),
            'source_url' => fake()->url(),
            'config' => [],
            'frequency' => 'daily',
            'is_active' => true,
            'last_run_at' => null,
        ];
    }
}
