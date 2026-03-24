<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\Agents\StructuredChatAnswerAgent;

beforeEach(function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_mode', 'local_only');
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.vector_enabled', false);
});

it('does not return answer resources in the ask response contract', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Historic Preservation GIS',
        'source_url' => 'https://maps.wichita.gov/historic-properties',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://maps.wichita.gov/historic-properties',
        'canonical_url' => 'https://maps.wichita.gov/historic-properties',
        'title' => 'Historic Preservation GIS',
        'content_text' => 'Use the city GIS site to check whether a property is in a historic district or is a local landmark.',
        'content_length' => 102,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Use the city GIS site to check whether a property is in a historic district or is a local landmark.',
        'content_length' => 102,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Check the city GIS site to see whether the property is in a historic district or has landmark status.',
            'citations' => [
                [
                    'title' => 'Historic Preservation GIS',
                    'source_url' => 'https://maps.wichita.gov/historic-properties',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.9,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'How can I tell if a property is historic?',
        'city_id' => $city->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('citations.0.source_url', 'https://maps.wichita.gov/historic-properties');

    expect($response->json())->not->toHaveKey('resources');
});

it('does not surface citations for refusal answers', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'General Source',
        'source_url' => 'https://example.com/source',
        'is_active' => true,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => "I'm sorry, but I can't assist with that.",
            'citations' => [
                [
                    'title' => 'General Source',
                    'source_url' => 'https://example.com/source',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.9,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'Help me do something unsafe.',
        'city_id' => $city->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('citations', []);
});
