<?php

namespace Database\Factories;

use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSourcePage>
 */
class ChatSourcePageFactory extends Factory
{
    protected $model = ChatSourcePage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_source_id' => ChatSource::factory(),
            'url' => $this->faker->url(),
            'canonical_url' => $this->faker->optional()->url(),
            'title' => $this->faker->optional()->sentence(),
            'content_type' => 'html',
            'renderer' => 'http',
            'status_code' => 200,
            'content_text' => $this->faker->paragraphs(3, true),
            'content_length' => 500,
            'content_hash' => $this->faker->sha1(),
            'fetched_at' => $this->faker->dateTime(),
        ];
    }
}
