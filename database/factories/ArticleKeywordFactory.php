<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\City;
use App\Models\Keyword;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleKeyword>
 */
class ArticleKeywordFactory extends Factory
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

        $name = fake()->words(2, true);

        $keyword = Keyword::query()->where('city_id', $city->id)->first() ?? Keyword::create([
            'city_id' => $city->id,
            'name' => $name,
            'slug' => Str::slug($name),
        ]);

        return [
            'article_id' => $article->id,
            'keyword_id' => $keyword->id,
            'confidence' => 0.7,
            'source' => 'llm',
        ];
    }
}
