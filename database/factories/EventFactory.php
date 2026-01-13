<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 30))->setTime(18, 0);
        $endsAt = (clone $startsAt)->addHours(2);

        return [
            'city_id' => City::factory(),
            'title' => fake()->sentence(4),
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'all_day' => false,
            'location_name' => fake()->company(),
            'location_address' => fake()->address(),
            'description' => fake()->paragraph(),
            'event_url' => fake()->url(),
            'source_hash' => sha1(fake()->uuid()),
        ];
    }
}
