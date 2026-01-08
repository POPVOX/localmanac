<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\ArticleAnalysis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ArticleAnalysis>
 */
class ArticleAnalysisFactory extends Factory
{
    protected $model = ArticleAnalysis::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $dimensions = [
            'comprehensibility' => fake()->randomFloat(2, 0.4, 0.9),
            'orientation' => fake()->randomFloat(2, 0.4, 0.9),
            'representation' => fake()->randomFloat(2, 0.4, 0.9),
            'agency' => fake()->randomFloat(2, 0.4, 0.9),
            'relevance' => fake()->randomFloat(2, 0.4, 0.9),
            'timeliness' => fake()->randomFloat(2, 0.4, 0.9),
        ];

        return [
            'article_id' => Article::factory(),
            'score_version' => 'crf_v1',
            'status' => 'llm_done',
            'heuristic_scores' => null,
            'llm_scores' => [
                'dimensions' => $dimensions,
                'justifications' => [
                    'comprehensibility' => fake()->sentence(),
                    'orientation' => fake()->sentence(),
                    'representation' => fake()->sentence(),
                    'agency' => fake()->sentence(),
                    'relevance' => fake()->sentence(),
                    'timeliness' => fake()->sentence(),
                ],
                'opportunities' => [],
                'confidence' => fake()->randomFloat(2, 0.4, 0.9),
            ],
            'final_scores' => $dimensions,
            'civic_relevance_score' => fake()->randomFloat(2, 0.4, 0.9),
            'model' => 'demo',
            'prompt_version' => 'demo',
            'confidence' => fake()->randomFloat(2, 0.4, 0.9),
            'last_scored_at' => now(),
        ];
    }
}
