<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use Illuminate\Support\Facades\Cache;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

it('answers with citations from ingested sources', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_driver', 'ingested');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Recycling & Trash',
        'source_url' => 'https://example.com/recycling',
        'tags' => ['trash'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/recycling',
        'canonical_url' => 'https://example.com/recycling',
        'title' => 'Recycling & Trash',
        'content_text' => 'Trash pickup is on Monday.',
        'content_length' => 28,
    ]);

    $chunk = ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Trash pickup is on Monday.',
        'content_length' => 28,
    ]);

    Prism::fake([
        new StructuredResponse(
            steps: collect([]),
            text: '',
            structured: [
                'answer' => 'Trash pickup is on Monday.',
                'citation_ids' => ['chunk_'.$chunk->id],
                'confidence' => 0.82,
            ],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake')
        ),
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'When is trash pickup?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'Trash pickup is on Monday.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/recycling');
});

it('returns fallback answer when evidence is insufficient', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_driver', 'ingested');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Garage Sales Online',
        'source_url' => 'https://example.com/garage-sales',
        'tags' => ['permits'],
        'priority' => 10,
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'How do I get a permit?',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertSeeText('I could not find the answer in the sources I checked.');
});

it('keeps reranked evidence when minimum score filtering is enabled', function () {
    Cache::flush();
    config()->set('scout.driver', 'collection');
    config()->set('chat.retrieval_driver', 'ingested');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.min_evidence_score_per_page', 2);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
    ]);

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Park Wichita',
        'source_url' => 'https://example.com/park-wichita',
        'tags' => ['parking'],
        'priority' => 10,
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/park-wichita',
        'canonical_url' => 'https://example.com/park-wichita',
        'title' => 'Park Wichita | Wichita, KS',
        'content_text' => 'Fee schedule and paid parking hours.',
        'content_length' => 35,
    ]);

    $chunk = ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Fee schedule: paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.',
        'content_length' => 95,
    ]);

    Prism::fake([
        new StructuredResponse(
            steps: collect([]),
            text: '',
            structured: [
                'answer' => 'Paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.',
                'citation_ids' => ['chunk_'.$chunk->id],
                'confidence' => 0.84,
            ],
            finishReason: FinishReason::Stop,
            usage: new Usage(0, 0),
            meta: new Meta('fake', 'fake')
        ),
    ]);

    $response = $this->withoutMiddleware()
        ->postJson('/ask', [
            'question' => 'fee',
            'city_id' => $city->id,
        ]);

    $response->assertOk()
        ->assertJsonPath('answer', 'Paid parking is $1 per hour from 8 a.m. to 6 p.m. Monday through Thursday.')
        ->assertJsonPath('citations.0.source_url', 'https://example.com/park-wichita');
});
