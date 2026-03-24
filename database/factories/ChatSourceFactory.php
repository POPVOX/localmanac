<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSource>
 */
class ChatSourceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => \App\Models\City::factory(),
            'name' => fake()->company(),
            'source_url' => fake()->url(),
            'description' => fake()->optional()->sentence(),
            'tags' => fake()->optional()->randomElements([
                'trash',
                'permits',
                'licenses',
                'parks',
                'kids',
            ], fake()->numberBetween(1, 3)),
            'priority' => fake()->numberBetween(0, 10),
            'is_active' => true,
            'frequency' => 'daily',
            'last_run_at' => null,
            'link_follow_mode' => 'auto',
            'link_limit' => 6,
            'crawl_renderer' => 'auto',
        ];
    }
}
