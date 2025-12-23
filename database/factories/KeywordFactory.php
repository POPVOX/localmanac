<?php

namespace Database\Factories;

use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = City::query()->first() ?? City::create([
            'name' => fake()->city(),
            'slug' => Str::slug(fake()->unique()->city()),
        ]);

        $name = fake()->words(2, true);

        return [
            'city_id' => $city->id,
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
