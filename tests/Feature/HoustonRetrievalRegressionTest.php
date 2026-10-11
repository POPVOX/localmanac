<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Services\Chat\ChatSourceRetriever;

it('ranks the actual service guide ahead of incidental matches and excludes error pages', function (string $question, string $title, string $text) {
    config(['scout.driver' => 'collection', 'chat.vector_enabled' => false, 'chat.reranking_enabled' => false,
        'chat.retrieval_v2_enabled' => false, 'chat.retrieval_chunk_limit' => 4, 'chat.retrieval_neighbor_window' => 0]);
    $source = ChatSource::factory()->create(['is_active' => true]);
    foreach (range(1, 40) as $i) {
        $page = ChatSourcePage::factory()->create(['chat_source_id' => $source->id,
            'title' => 'County fleet award '.$i, 'url' => 'https://example.gov/awards/'.$i, 'canonical_url' => null]);
        ChatSourceChunk::factory()->create(['chat_source_page_id' => $page->id,
            'content' => 'Get news about building permit statistics and submit requests. Hazardous waste and historic designation were mentioned in a speech. Demolition permit figures increased.']);
    }
    $guide = ChatSourcePage::factory()->create(['chat_source_id' => $source->id, 'title' => $title,
        'url' => 'https://example.gov/guide', 'canonical_url' => null, 'status_code' => 200]);
    ChatSourceChunk::factory()->create(['chat_source_page_id' => $guide->id, 'content' => $text]);
    $error = ChatSourcePage::factory()->create(['chat_source_id' => $source->id,
        'title' => '404 Not Found', 'url' => 'https://example.gov/missing', 'canonical_url' => null, 'status_code' => 200]);
    ChatSourceChunk::factory()->create(['chat_source_page_id' => $error->id, 'content' => $text]);
    $httpError = ChatSourcePage::factory()->create(['chat_source_id' => $source->id,
        'title' => $title, 'url' => 'https://example.gov/unavailable', 'canonical_url' => null, 'status_code' => 503]);
    ChatSourceChunk::factory()->create(['chat_source_page_id' => $httpError->id, 'content' => $text]);
    $result = app(ChatSourceRetriever::class)->retrieve(collect([$source]), $question, $source->city_id);
    expect($result['evidence'][0]['source_url'])->toBe('https://example.gov/guide')
        ->and(array_column($result['evidence'], 'source_url'))->not->toContain('https://example.gov/missing', 'https://example.gov/unavailable');
})->with([
    ['How do I get a building permit?', 'Building permit application', 'Apply for a building permit online. Submit plans, pay the fee and schedule inspections.'],
    ['How do I get a demolition permit?', 'Demolition permit application', 'Before demolition, apply for a demolition permit. Submit the property survey and required utility releases.'],
    ['How do I dispose of hazardous waste?', 'Household hazardous waste disposal', 'Bring household hazardous waste and proof of residency to the collection center.'],
    ['How do I get historic designation?', 'Historic landmark designation', 'For historic designation, request a meeting with the historic planner and submit the signed application.'],
]);

it('does not return generic get matches for a procedural question with no relevant content', function () {
    config(['chat.vector_enabled' => false, 'chat.reranking_enabled' => false]);
    $source = ChatSource::factory()->create(['is_active' => true]);
    $page = ChatSourcePage::factory()->create(['chat_source_id' => $source->id, 'title' => 'County fleet awards']);
    ChatSourceChunk::factory()->create(['chat_source_page_id' => $page->id, 'content' => 'Get news about the county fleet award and this new building.']);
    $result = app(ChatSourceRetriever::class)->retrieve(collect([$source]), 'How do I get a building permit?');
    expect($result['evidence'])->toBeEmpty();
});

it('keeps a short service guide when one long document has many higher ranked chunks', function () {
    config(['chat.vector_enabled' => false, 'chat.reranking_enabled' => false,
        'chat.retrieval_v2_enabled' => false, 'chat.retrieval_chunk_limit' => 4, 'chat.retrieval_neighbor_window' => 0]);
    $source = ChatSource::factory()->create(['is_active' => true]);
    $long = ChatSourcePage::factory()->create(['chat_source_id' => $source->id,
        'title' => 'Hazardous waste statistics', 'canonical_url' => null]);
    foreach (range(0, 39) as $index) {
        ChatSourceChunk::factory()->create(['chat_source_page_id' => $long->id, 'chunk_index' => $index,
            'content' => 'Hazardous waste reporting statistics for district '.$index]);
    }
    $guide = ChatSourcePage::factory()->create(['chat_source_id' => $source->id,
        'title' => 'Environmental Service Centers', 'url' => 'https://example.gov/esc', 'canonical_url' => null]);
    ChatSourceChunk::factory()->create(['chat_source_page_id' => $guide->id,
        'content' => 'Bring household hazardous waste and proof of residency to the environmental service center.']);
    $result = app(ChatSourceRetriever::class)->retrieve(collect([$source]), 'How do I dispose of hazardous waste?');
    expect(array_column($result['evidence'], 'source_url'))->toContain($guide->url);
});
