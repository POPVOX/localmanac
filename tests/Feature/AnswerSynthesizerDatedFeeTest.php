<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AnswerSynthesizer;

it('checks dated fees in the final streamed answer', function (string $modelAnswer, bool $accepted) {
    $this->travelTo(now()->setDate(2026, 10, 11));
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.seed_fallback_enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create(['name' => 'Jackson', 'timezone' => 'America/Chicago']);
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Jackson Construction Guide (January 2022 edition)',
        'source_url' => 'https://www.jacksontn.gov/construction-guide.pdf',
        'is_active' => true,
    ]);
    $text = 'Residential building demolition permit fee: $50.00. Commercial building demolition permit fee: $75.00.';
    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => $source->source_url,
        'canonical_url' => $source->source_url,
        'title' => $source->name,
        'content_text' => $text,
        'content_length' => strlen($text),
    ]);
    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'chunk_index' => 0,
        'content' => $text,
        'content_length' => strlen($text),
        'embedding' => null,
        'embedding_model' => null,
    ]);
    StreamingChatAnswerAgent::fake([$modelAnswer]);

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'What is the residential demolition permit fee?',
        city: $city,
        sources: collect([$source]),
        user: User::factory()->create(),
        conversationId: null,
        onDelta: fn (string $delta): null => null,
    );

    expect($result['answer'])->toBe($accepted
        ? $modelAnswer
        : 'I could not find the answer in the sources I checked.');
})->with([
    'unqualified fee is rejected' => ['You must pay $50.00 for a residential demolition permit.', false],
    'partial no-answer does not bypass the guard' => ['The residential demolition permit fee is $50.00. I could not find application steps.', false],
    'dated qualified fee is retained' => ['The January 2022 guide lists $50 for a residential demolition permit. I have not verified the current fee.', true],
]);
