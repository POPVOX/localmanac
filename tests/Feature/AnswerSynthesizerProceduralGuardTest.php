<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AnswerSynthesizer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('narrows procedural answers when ordinance-style evidence does not support a complete process', function () {
    config()->set('chat.vector_enabled', false);
    config()->set('chat.tools.web_search.enabled', false);
    config()->set('chat.tools.similarity.enabled', false);
    config()->set('chat.retrieval_neighbor_window', 0);

    $city = City::factory()->create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);

    $user = User::factory()->create();

    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita Government',
        'source_url' => 'https://www.wichita.gov/27/Government',
        'priority' => 10,
        'is_active' => true,
    ]);

    $definitionPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12635',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12635',
        'title' => 'Building Code Definition',
        'content_text' => 'Demolition means activity that requires a demolition permit under the building code.',
        'content_length' => 79,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $definitionPage->id,
        'chunk_index' => 0,
        'content' => 'Demolition means activity that requires a demolition permit under the building code.',
        'content_length' => 79,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $historicPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12636',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12636',
        'title' => 'Historic Resources Demolition Review',
        'content_text' => 'c) The Office of Central Inspection is prohibited from issuing any permit during interim control. If the project involves demolition of a historic resource or property in a historic district, submit a Certificate of Appropriateness for review before work begins.',
        'content_length' => 160,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $historicPage->id,
        'chunk_index' => 0,
        'content' => 'c) The Office of Central Inspection is prohibited from issuing any permit during interim control. If the project involves demolition of a historic resource or property in a historic district, submit a Certificate of Appropriateness for review before work begins.',
        'content_length' => 258,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    $inspectionPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => 'https://www.wichita.gov/DocumentCenter/View/12637',
        'canonical_url' => 'https://www.wichita.gov/DocumentCenter/View/12637',
        'title' => 'Demolition Completion Requirements',
        'content_text' => 'Final inspection is made after demolition debris, foundations, and utilities are removed from the property and disposed of properly.',
        'content_length' => 126,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $inspectionPage->id,
        'chunk_index' => 0,
        'content' => 'Final inspection is made after demolition debris, foundations, and utilities are removed from the property and disposed of properly.',
        'content_length' => 126,
        'embedding' => null,
        'embedding_model' => null,
    ]);

    StreamingChatAnswerAgent::fake([
        "1. Submit the application to the central inspection office.\n2. Pay the fee and post a bond.\n3. Schedule the final inspection.",
    ]);

    $result = app(AnswerSynthesizer::class)->synthesizeStreaming(
        question: 'How do I get a demolition permit?',
        city: $city,
        sources: collect([$source]),
        user: $user,
        conversationId: null,
        onDelta: fn (string $delta): null => null,
        originalQuestion: 'How do I get a demolition permit?',
    );

    expect($result['answer'])
        ->toContain('permit or formal review may be required')
        ->toContain('additional review may apply in cases involving historic properties or historic districts')
        ->toContain('The full step-by-step process is not clearly described in the available sources.')
        ->not->toContain('1.')
        ->not->toContain('post a bond')
        ->not->toContain('central inspection office')
        ->not->toContain('c)')
        ->not->toContain('The sources mention:')
        ->not->toContain('subsection')
        ->and($result['citations'])->not->toBeEmpty();
});
