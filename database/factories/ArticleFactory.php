<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Article>
 */
class ArticleFactory extends Factory
{
    protected $model = Article::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'scraper_id' => null,
            'title' => fake()->sentence(6),
            'summary' => fake()->paragraph(),
            'published_at' => now()->subDays(fake()->numberBetween(0, 14)),
            'content_type' => 'html',
            'canonical_url' => fake()->unique()->url(),
            'content_hash' => fake()->sha1(),
            'status' => 'published',
        ];
    }
}
