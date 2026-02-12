<?php

use App\Models\ChatSource;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\Agents\ChatCitationAgent;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AskService;
use Illuminate\Support\Facades\DB;

it('persists conversation records and returns a conversation id for streaming user answers', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling & Trash',
        'source_url' => 'https://example.com/recycling',
        'priority' => 10,
        'is_active' => true,
    ]);

    $user = User::factory()->create();

    StreamingChatAnswerAgent::fake([
        'Trash pickup is on Monday.',
    ]);

    ChatCitationAgent::fake([
        [
            'citations' => [
                [
                    'title' => $source->name,
                    'source_url' => $source->source_url,
                    'type' => 'html',
                ],
            ],
            'confidence' => 0.9,
        ],
    ]);

    $result = app(AskService::class)->answerStreamingForUser(
        question: 'When is trash pickup?',
        citySelector: $city->id,
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
    );

    expect($result['answer'])->toBe('Trash pickup is on Monday.')
        ->and($result['conversation_id'])->toBeString()
        ->and($result['conversation_id'])->not->toBe('');

    $this->assertDatabaseHas('agent_conversations', [
        'id' => $result['conversation_id'],
        'user_id' => $user->id,
    ]);

    expect(DB::table('agent_conversation_messages')
        ->where('conversation_id', $result['conversation_id'])
        ->where('user_id', $user->id)
        ->count())->toBe(2);
});
