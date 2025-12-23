<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\City;
use App\Models\IssueArea;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleIssueArea>
 */
class ArticleIssueAreaFactory extends Factory
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

        $issueArea = IssueArea::query()->where('city_id', $city->id)->first() ?? IssueArea::create([
            'city_id' => $city->id,
            'name' => fake()->words(2, true),
            'slug' => Str::slug(fake()->unique()->words(2, true)),
        ]);

        return [
            'article_id' => $article->id,
            'issue_area_id' => $issueArea->id,
            'confidence' => 0.7,
            'source' => 'llm',
        ];
    }
}
