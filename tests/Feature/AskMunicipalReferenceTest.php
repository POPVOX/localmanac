<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AskService;

it('answers municipal contact and agenda questions from indexed pages instead of unrelated calendar meetings', function () {
    config([
        'chat.vector_enabled' => false,
        'chat.tools.web_search.enabled' => false,
        'chat.tools.similarity.enabled' => false,
        'chat.answer_quality_enabled' => false,
    ]);
    $city = City::factory()->create(['name' => 'Stamford', 'slug' => 'stamford', 'timezone' => 'America/New_York']);
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Stamford Board of Representatives',
        'source_url' => 'https://boardofreps.org/',
        'is_active' => true,
    ]);
    $content = 'Contact Stamford Board of Representatives at 203-977-4024 or bdreps@stamfordct.gov. Meeting agendas are available on the Board of Representatives website.';
    $page = ChatSourcePage::factory()->create([
        'chat_source_id' => $source->id,
        'url' => $source->source_url,
        'canonical_url' => $source->source_url,
        'title' => $source->name,
        'content_text' => $content,
    ]);
    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $page->id,
        'content' => $content,
        'embedding' => null,
        'embedding_model' => null,
    ]);
    Event::factory()->create([
        'city_id' => $city->id,
        'title' => 'School Board Committee Meeting',
        'description' => 'School board meeting with agenda.',
        'starts_at' => now()->addDays(2),
    ]);
    StreamingChatAnswerAgent::fake(["To contact Stamford's Board of Representatives, call 203-977-4024. Meeting agendas are available at https://boardofreps.org/."]);

    $result = app(AskService::class)->answerStreamingForUser(
        "How can I contact Stamford's Board of Representatives, and where can I find its meeting agendas?",
        $city->id, User::factory()->create(), null, fn () => null,
    );

    expect($result['answer'])->toContain('203-977-4024')->not->toContain('School Board Committee Meeting')
        ->and(collect($result['citations'])->pluck('source_url')->all())->toContain($source->source_url);
});
