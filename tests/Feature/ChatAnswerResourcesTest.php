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

it('surfaces a GIS link resource for historic property questions', function () {
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
        ->assertJsonPath('resources.0.type', 'link')
        ->assertJsonPath('resources.0.label', 'Open GIS site')
        ->assertJsonPath('resources.0.value', 'Historic Preservation GIS')
        ->assertJsonPath('resources.0.url', 'https://maps.wichita.gov/historic-properties');
});

it('surfaces an address map resource for hazardous waste questions', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Household Hazardous Waste',
        'source_url' => 'https://example.com/hazardous-waste',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/hazardous-waste',
        'canonical_url' => 'https://example.com/hazardous-waste',
        'title' => 'Household Hazardous Waste',
        'content_text' => 'Household hazardous waste drop-off is at 801 Stillwell St, Wichita, KS 67213.',
        'content_length' => 78,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Household hazardous waste drop-off is at 801 Stillwell St, Wichita, KS 67213.',
        'content_length' => 78,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Take hazardous waste to the household hazardous waste drop-off facility.',
            'citations' => [
                [
                    'title' => 'Household Hazardous Waste',
                    'source_url' => 'https://example.com/hazardous-waste',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.87,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'Where do I take hazardous waste?',
        'city_id' => $city->id,
    ]);

    expect(collect($response->json('resources'))->contains(
        fn (array $resource): bool => $resource['type'] === 'address'
            && $resource['label'] === 'Open drop-off map'
            && $resource['value'] === '801 Stillwell St, Wichita, KS 67213'
            && $resource['url'] === 'https://www.google.com/maps/search/?api=1&query=801%20Stillwell%20St%2C%20Wichita%2C%20KS%2067213'
    ))->toBeTrue();
});

it('surfaces a phone resource when the answer tells people to call', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Report a Water Leak',
        'source_url' => 'https://example.com/report-water-leak',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/report-water-leak',
        'canonical_url' => 'https://example.com/report-water-leak',
        'title' => 'Report a Water Leak',
        'content_text' => 'To report a water leak, call 316-262-6000.',
        'content_length' => 44,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'To report a water leak, call 316-262-6000.',
        'content_length' => 44,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Call city water utilities for help with the leak.',
            'citations' => [
                [
                    'title' => 'Report a Water Leak',
                    'source_url' => 'https://example.com/report-water-leak',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.86,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'Who should I call about a water leak?',
        'city_id' => $city->id,
    ]);

    expect(collect($response->json('resources'))->contains(
        fn (array $resource): bool => $resource['type'] === 'phone'
            && $resource['label'] === 'Call Report a Water Leak'
            && $resource['value'] === '(316) 262-6000'
            && $resource['url'] === 'tel:3162626000'
    ))->toBeTrue();
});

it('omits a phone resource when the evidence does not include a grounded phone number', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Report a Water Leak',
        'source_url' => 'https://example.com/report-water-leak',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/report-water-leak',
        'canonical_url' => 'https://example.com/report-water-leak',
        'title' => 'Report a Water Leak',
        'content_text' => 'To report a water leak, use the online service request form.',
        'content_length' => 60,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'To report a water leak, use the online service request form.',
        'content_length' => 60,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Contact water utilities for help with the leak.',
            'citations' => [
                [
                    'title' => 'Report a Water Leak',
                    'source_url' => 'https://example.com/report-water-leak',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.9,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'Who should I call about a water leak?',
        'city_id' => $city->id,
    ]);

    expect(collect($response->json('resources'))->contains(
        fn (array $resource): bool => $resource['type'] === 'phone'
    ))->toBeFalse();
});

it('filters infrastructure citations out of answer resources', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita FAQ',
        'source_url' => 'https://www.wichita.gov/m/faq',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/m/faq',
        'canonical_url' => 'https://www.wichita.gov/m/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'Call the Planning Department at 316-268-4421 for historic preservation assistance.',
        'content_length' => 82,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Call the Planning Department at 316-268-4421 for historic preservation assistance.',
        'content_length' => 82,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => 'Call the Planning Department at 316-268-4421 for historic preservation help.',
            'citations' => [
                [
                    'title' => 'Email Protection | Cloudflare',
                    'source_url' => 'https://www.wichita.gov/cdn-cgi/l/email-protection',
                    'type' => 'html',
                ],
                [
                    'title' => 'Frequently Asked Questions',
                    'source_url' => 'https://www.wichita.gov/m/faq',
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
        ->assertJsonPath('citations.0.source_url', 'https://www.wichita.gov/m/faq')
        ->assertJsonCount(1, 'citations');

    expect(collect($response->json('resources'))->contains(
        fn (array $resource): bool => $resource['url'] === 'https://www.wichita.gov/m/faq'
    ))->toBeTrue();
});

it('does not surface citations or resources for refusal answers', function () {
    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita FAQ',
        'source_url' => 'https://www.wichita.gov/m/faq',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/m/faq',
        'canonical_url' => 'https://www.wichita.gov/m/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'General service information.',
        'content_length' => 28,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'General service information.',
        'content_length' => 28,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StructuredChatAnswerAgent::fake([
        [
            'answer' => "I'm sorry, but I can't assist with that.",
            'citations' => [
                [
                    'title' => 'Frequently Asked Questions',
                    'source_url' => 'https://www.wichita.gov/m/faq',
                    'type' => 'html',
                ],
            ],
            'source_mode' => 'local',
            'confidence' => 0.9,
        ],
    ]);

    $response = $this->withoutMiddleware()->postJson('/ask', [
        'question' => 'How can I disrupt the Wichita water supply?',
        'city_id' => $city->id,
    ]);

    $response->assertSuccessful()
        ->assertJsonPath('citations', [])
        ->assertJsonPath('resources', []);
});
