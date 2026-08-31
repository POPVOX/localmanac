<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;

it('evaluates a city-scoped retrieval dataset', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.reranking_enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create(['slug' => 'evaluation-city']);
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'is_active' => true,
    ]);
    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://city.test/permits',
        'canonical_url' => 'https://city.test/permits',
        'content_text' => 'Garage sale permits cost two dollars.',
    ]);
    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'content' => 'Garage sale permits cost two dollars.',
        'content_length' => 36,
    ]);

    $dataset = tempnam(sys_get_temp_dir(), 'retrieval-eval-');
    file_put_contents($dataset, json_encode([
        'name' => 'Command test',
        'cases' => [[
            'id' => 'permit-cost',
            'city' => $city->slug,
            'question' => 'What do garage sale permits cost?',
            'required_source_urls' => ['https://city.test/permits'],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('chat:evaluate-retrieval', [
            'file' => $dataset,
            '--profile' => 'v2',
        ])
            ->expectsOutputToContain('1/1 passed')
            ->assertSuccessful();
    } finally {
        unlink($dataset);
    }
});
