<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\EventSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventSourceItem>
 */
class EventSourceItemFactory extends Factory
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
            'event_source_id' => EventSource::factory(),
            'external_id' => fake()->boolean(40) ? fake()->uuid() : null,
            'source_url' => fake()->boolean(80) ? fake()->url() : null,
            'raw_payload' => [
                'title' => fake()->sentence(4),
            ],
            'fetched_at' => now(),
        ];
    }
}
