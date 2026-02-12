<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\SimilaritySearch;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('returns lexical similarity matches when vector retrieval is unavailable', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.similarity.limit', 8);

    $city = City::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
        'source_url' => 'https://example.com/how-do-i',
        'name' => 'How Do I',
    ]);

    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://example.com/report-water-leak',
        'canonical_url' => 'https://example.com/report-water-leak',
        'title' => 'Report a Water Leak',
        'content_type' => 'html',
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => 'Report a water leak by calling 316-262-6000.',
        'content_length' => 44,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $method = new ReflectionMethod(AnswerSynthesizer::class, 'localSimilaritySearch');
    $method->setAccessible(true);

    /** @var SimilaritySearch $tool */
    $tool = $method->invoke(app(AnswerSynthesizer::class), collect([$source]));
    $results = ($tool->using)('How do I report a water leak?');

    expect($results)->toHaveCount(1)
        ->and($results[0]['source_url'])->toBe('https://example.com/report-water-leak')
        ->and($results[0]['snippet'])->toContain('316-262-6000');
});
