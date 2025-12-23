<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\City;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleEntity>
 */
class ArticleEntityFactory extends Factory
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

        $article = Article::query()->where('city_id', $city->id)->first() ?? Article::create([
            'city_id' => $city->id,
            'title' => fake()->sentence(6),
            'status' => 'published',
            'content_type' => 'html',
        ]);

        return [
            'article_id' => $article->id,
            'entity_type' => fake()->randomElement(['person', 'organization', 'location']),
            'entity_id' => null,
            'display_name' => fake()->name(),
            'confidence' => 0.7,
            'source' => 'llm',
        ];
    }
}
