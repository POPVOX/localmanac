<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Models\Event;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\AskService;

it('answers municipal reference questions from indexed pages instead of calendar listings', function (string $name, string $url, string $content, string $question, string $answer, string $expected) {
    config([
        'chat.vector_enabled' => false,
        'chat.tools.web_search.enabled' => false,
        'chat.tools.similarity.enabled' => false,
        'chat.answer_quality_enabled' => false,
    ]);
    $city = City::factory()->create(['name' => 'Stamford', 'slug' => 'stamford', 'timezone' => 'America/New_York']);
    $source = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => $name,
        'source_url' => $url,
        'is_active' => true,
    ]);
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
    StreamingChatAnswerAgent::fake([$answer]);

    $result = app(AskService::class)->answerStreamingForUser(
        $question,
        $city->id, User::factory()->create(), null, fn () => null,
    );

    expect($result['answer'])->toContain($expected)->not->toContain('School Board Committee Meeting')
        ->and(collect($result['citations'])->pluck('source_url')->all())->toContain($source->source_url);
})->with([
    'contact and agendas' => [
        'Stamford Board of Representatives',
        'https://boardofreps.org/',
        'Contact Stamford Board of Representatives at 203-977-4024 or bdreps@stamfordct.gov. Meeting agendas are available on the Board of Representatives website.',
        "How can I contact Stamford's Board of Representatives, and where can I find its meeting agendas?",
        "To contact Stamford's Board of Representatives, call 203-977-4024. Meeting agendas are available at https://boardofreps.org/.",
        '203-977-4024',
    ],
    'public participation' => [
        'Stamford Board of Education',
        'https://www.stamfordpublicschools.org/board-of-education',
        'Stamford Board of Education public participation: speakers must sign up before the meeting and have three minutes for public comment.',
        'How can I speak at a Stamford Board of Education meeting?',
        'To speak at a Stamford Board of Education meeting, sign up before the meeting. Speakers have three minutes for public comment.',
        'sign up before the meeting',
    ],
]);
