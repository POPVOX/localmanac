<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleBody;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleBody>
 */
class ArticleBodyFactory extends Factory
{
    protected $model = ArticleBody::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'raw_text' => null,
            'cleaned_text' => fake()->paragraphs(5, true),
            'raw_html' => null,
            'lang' => 'en',
            'extracted_at' => now(),
            'extraction_status' => 'success',
            'extraction_error' => null,
            'extraction_meta' => null,
        ];
    }
}
