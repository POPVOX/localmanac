<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleOpportunity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleOpportunity>
 */
class ArticleOpportunityFactory extends Factory
{
    protected $model = ArticleOpportunity::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 14))->startOfHour();

        return [
            'article_id' => Article::factory(),
            'kind' => fake()->randomElement(['meeting', 'comment_period', 'deadline']),
            'title' => fake()->sentence(4),
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'location' => fake()->city(),
            'url' => fake()->url(),
            'notes' => fake()->sentence(),
            'source' => 'llm',
            'confidence' => fake()->randomFloat(2, 0.4, 0.9),
        ];
    }
}
