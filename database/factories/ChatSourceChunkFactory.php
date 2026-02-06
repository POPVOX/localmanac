<?php

namespace Database\Factories;

use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatSourceChunk>
 */
class ChatSourceChunkFactory extends Factory
{
    protected $model = ChatSourceChunk::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_source_page_id' => ChatSourcePage::factory(),
            'chunk_index' => 0,
            'content' => $this->faker->paragraphs(2, true),
            'content_length' => 300,
            'content_hash' => $this->faker->sha1(),
            'embedding_model' => null,
            'embedding' => null,
        ];
    }
}
