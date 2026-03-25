<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleChunk>
 */
class ArticleChunkFactory extends Factory
{
    protected $model = ArticleChunk::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'article_id' => Article::factory(),
            'chunk_index' => 0,
            'content' => $this->faker->paragraphs(2, true),
            'content_length' => 300,
            'content_hash' => $this->faker->sha1(),
            'embedding_model' => null,
            'embedding' => null,
        ];
    }
}
