<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\ChatSourceRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('retrieves ingested chunks using full text search', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Brooks Landfill',
        'source_url' => 'https://example.com/brooks-landfill',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/brooks-landfill',
        'canonical_url' => 'https://example.com/brooks-landfill',
        'title' => 'Brooks Landfill',
        'content_text' => 'Fee schedule for Brooks Landfill. Secured load is $44.85 per ton.',
        'content_length' => 78,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Fee schedule for Brooks Landfill. Secured load is $44.85 per ton.',
        'content_length' => 78,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'What is the fee schedule for the Brooks Landfill?');

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['source_url'])->toBe('https://example.com/brooks-landfill')
        ->and($result['meta']['pages_fetched'])->toBe(1);
});

it('expands neighbor chunks for additional context', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.retrieval_chunk_limit', 1);
    config()->set('chat.retrieval_neighbor_window', 1);
    config()->set('chat.retrieval_max_evidence', 3);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Brooks Landfill',
        'source_url' => 'https://example.com/brooks-landfill',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/brooks-landfill',
        'canonical_url' => 'https://example.com/brooks-landfill',
        'title' => 'Brooks Landfill',
        'content_text' => 'Fee schedule for Brooks Landfill.',
        'content_length' => 36,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Fee schedule',
        'content_length' => 12,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 1,
        'content' => 'Secured load is $44.85 per ton.',
        'content_length' => 33,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 2,
        'content' => 'Unsecured load is $58.00 per ton.',
        'content_length' => 34,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'secured load');

    expect($result['evidence'])->toHaveCount(3)
        ->and(collect($result['evidence'])->pluck('snippet')->implode(' '))
        ->toContain('Secured load is $44.85 per ton.');
});

it('relaxes full text search when strict query matches nothing', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.retrieval_chunk_limit', 5);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Garage Sales Online',
        'source_url' => 'https://example.com/garage-sales',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/garage-sales',
        'canonical_url' => 'https://example.com/garage-sales',
        'title' => 'Garage Sales Online',
        'content_text' => 'Garage sale permit is $2.50 per day with a $1 credit card transaction fee.',
        'content_length' => 84,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Garage sale permit is $2.50 per day with a $1 credit card transaction fee.',
        'content_length' => 84,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'How much does a garage sale permit cost per day, and what is the credit card fee?');

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['source_url'])->toBe('https://example.com/garage-sales');
});

it('prioritizes operational chunks for schedule and hours questions', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.retrieval_chunk_limit', 8);
    config()->set('chat.retrieval_neighbor_window', 0);
    config()->set('chat.retrieval_max_evidence', 8);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Park Wichita',
        'source_url' => 'https://example.com/park-wichita',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/park-wichita',
        'canonical_url' => 'https://example.com/park-wichita',
        'title' => 'Park Wichita | Wichita, KS',
        'content_text' => 'Hours and reimbursement details.',
        'content_length' => 32,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Businesses may request reimbursement through their HR department.',
        'content_length' => 68,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 1,
        'content' => 'Hours of operation for paid parking: Monday-Thursday 8 a.m.-6 p.m.; Friday-Saturday 8 a.m.-9 p.m.',
        'content_length' => 112,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'What are the paid parking hours for Park Wichita?');

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['snippet'])->toContain('Hours of operation for paid parking');
});

it('retains short intent tokens like id when ranking evidence', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.retrieval_chunk_limit', 5);
    config()->set('chat.retrieval_neighbor_window', 0);
    config()->set('chat.retrieval_max_evidence', 5);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Apply For',
        'source_url' => 'https://example.com/apply-for',
        'is_active' => true,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/apply-for',
        'canonical_url' => 'https://example.com/apply-for',
        'title' => 'Apply For (Wichita)',
        'content_text' => 'City ID details.',
        'content_length' => 16,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'The City ID number is ICT-A0 00000 and it is not valid for voting.',
        'content_length' => 70,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 1,
        'content' => 'City services are available online for Wichita residents.',
        'content_length' => 56,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'id valid');

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['snippet'])->toContain('not valid for voting');
});

it('ignores infrastructure pages when retrieving evidence', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);

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

    $realPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/m/faq',
        'canonical_url' => 'https://www.wichita.gov/m/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'Call 316-268-4421 for historic preservation assistance.',
        'content_length' => 58,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $realPage->id,
        'chunk_index' => 0,
        'content' => 'Call 316-268-4421 for historic preservation assistance.',
        'content_length' => 58,
    ]);

    $cloudflarePage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/cdn-cgi/l/email-protection',
        'canonical_url' => null,
        'title' => 'Email Protection | Cloudflare',
        'content_text' => 'Email Protection. The website from which you got to this page is protected by Cloudflare.',
        'content_length' => 88,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $cloudflarePage->id,
        'chunk_index' => 0,
        'content' => 'Email Protection. The website from which you got to this page is protected by Cloudflare.',
        'content_length' => 88,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(collect([$source]), 'historic preservation phone number');

    expect($result['evidence'])->toHaveCount(1)
        ->and($result['evidence'][0]['source_url'])->toBe('https://www.wichita.gov/m/faq')
        ->and($result['evidence'][0]['snippet'])->toContain('316-268-4421');
});
