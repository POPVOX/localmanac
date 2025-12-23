<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\City;
use App\Support\Claims\ClaimTypes;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Claim>
 */
class ClaimFactory extends Factory
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

        $value = [
            'name' => fake()->name(),
        ];

        return [
            'city_id' => $city->id,
            'article_id' => $article->id,
            'claim_type' => ClaimTypes::ARTICLE_MENTIONS_PERSON,
            'subject_type' => null,
            'subject_id' => null,
            'value_json' => $value,
            'evidence_json' => [
                [
                    'quote' => fake()->sentence(),
                ],
            ],
            'confidence' => 0.7,
            'source' => 'llm',
            'model' => 'test-model',
            'prompt_version' => 'enrich_v1_prompt_001',
            'status' => 'proposed',
            'value_hash' => sha1((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }
}
