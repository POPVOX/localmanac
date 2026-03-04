<?php

namespace Database\Factories;

use App\Enums\SiteFeedbackType;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteFeedback>
 */
class SiteFeedbackFactory extends Factory
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
            'type' => fake()->randomElement(SiteFeedbackType::values()),
            'message' => fake()->realTextBetween(120, 240),
            'page_url' => fake()->url(),
            'route_name' => fake()->optional()->randomElement([
                'home',
                'dashboard',
                'demo.calendar',
                'articles.show',
            ]),
            'city_id' => City::factory(),
        ];
    }

    public function withoutCity(): static
    {
        return $this->state(fn (array $attributes): array => [
            'city_id' => null,
        ]);
    }
}
