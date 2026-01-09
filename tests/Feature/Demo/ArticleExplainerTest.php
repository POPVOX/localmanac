<?php

use App\Models\Article;
use App\Models\ArticleAnalysis;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Models\City;
use App\Models\Organization;
use App\Models\ProcessTimelineItem;
use App\Models\Scraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

test('it renders the explainer with participation actions and metadata', function () {
    $now = Carbon::parse('2025-01-10 09:00:00', 'UTC');
    Carbon::setTestNow($now);

    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $organization = Organization::create([
        'city_id' => $city->id,
        'name' => 'Wichita Documenters',
        'slug' => Str::slug('Wichita Documenters'),
        'type' => 'nonprofit',
    ]);

    $scraper = Scraper::create([
        'city_id' => $city->id,
        'organization_id' => $organization->id,
        'name' => 'Documenters Feed',
        'slug' => Str::slug('Documenters Feed'),
        'type' => 'rss',
        'source_url' => 'https://example.com/feed',
        'is_enabled' => true,
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Downtown Council Update',
        'summary' => 'City council previewed a downtown redevelopment proposal.',
        'published_at' => Carbon::parse('2025-01-06 00:00:00', 'UTC'),
        'canonical_url' => 'https://example.com/proposal',
        'status' => 'decision_pending',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Downtown redevelopment details. ', 40),
        'updated_at' => Carbon::parse('2025-01-08 09:00:00', 'UTC'),
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'final_scores' => [
            'opportunities' => [
                [
                    'type' => 'public_comment',
                    'date' => '2025-01-15',
                    'time' => '5:00 PM',
                    'location' => 'City Clerk',
                    'url' => 'https://example.com/comment',
                    'description' => 'Written comments are read into the official record.',
                ],
                [
                    'type' => 'meeting',
                    'date' => '2025-01-22',
                    'time' => '6:00 PM',
                    'location' => 'City Hall, Room 201',
                    'url' => 'https://example.com/meeting',
                    'description' => 'Public hearing on downtown redevelopment.',
                ],
            ],
        ],
        'civic_relevance_score' => 0.66,
        'last_scored_at' => now(),
    ]);

    ArticleExplainer::create([
        'article_id' => $article->id,
        'city_id' => $article->city_id,
        'whats_happening' => 'Council advanced the downtown redevelopment plan.',
        'why_it_matters' => 'Residents could see changes to traffic and housing.',
        'key_details' => ['City Hall presentation scheduled.'],
        'source' => 'analysis_llm',
    ]);

    $response = $this->get(route('demo.articles.show', $article));

    $response->assertOk()
        ->assertSee('Downtown Council Update')
        ->assertSee('Wichita Documenters')
        ->assertSee('January 6, 2025')
        ->assertSee('Updated 2 days ago')
        ->assertSee("What's happening")
        ->assertSee('Why it matters')
        ->assertSee('Submit a Comment')
        ->assertSee('Attend the Hearing')
        ->assertSee('Add to calendar')
        ->assertSee('Closes Jan 15')
        ->assertSee('Read the Proposal')
        ->assertDontSee('Downtown redevelopment details.')
        ->assertDontSee('Orientation')
        ->assertSeeInOrder([
            "What's happening",
            'Why it matters',
            'Where we are in the process',
            'Source Article',
        ]);

    $response->assertSee('href="https://example.com/proposal"', false);

    Carbon::setTestNow();
});

test('it renders participation badges for public comments', function () {
    $article = Article::factory()->create();

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Cleaned text excerpt. ', 20),
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'final_scores' => [
            'opportunities' => [
                [
                    'type' => 'public_comment',
                    'date' => '2025-01-15',
                    'time' => '5:00 PM',
                    'url' => 'https://example.com/comment',
                    'description' => 'Submit feedback about the proposed changes.',
                ],
            ],
        ],
    ]);

    $response = $this->get(route('demo.articles.show', $article));

    $response->assertOk()
        ->assertSee('Submit a Comment')
        ->assertSee('Submit online')
        ->assertSee('Closes Jan 15');
});

test('it renders civic action times in the city timezone', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Cleaned text excerpt. ', 20),
    ]);

    ArticleAnalysis::factory()->create([
        'article_id' => $article->id,
        'final_scores' => [
            'opportunities' => [
                [
                    'type' => 'meeting',
                    'date' => '2025-10-21',
                    'time' => '09:00',
                    'location' => 'City Hall',
                    'description' => 'Public hearing on the proposal.',
                ],
            ],
        ],
    ]);

    $response = $this->get(route('demo.articles.show', $article));

    $response->assertOk()
        ->assertSee('9:00 AM')
        ->assertDontSee('4:00 AM');
});

test('it renders the explainer when analysis is missing', function () {
    $article = Article::factory()->create([
        'summary' => 'Summary should not appear.',
        'canonical_url' => null,
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Unique fallback text. ', 8),
    ]);

    $response = $this->get(route('demo.articles.show', $article));

    $response->assertOk()
        ->assertSee('Unique fallback text.')
        ->assertDontSee('Summary should not appear.')
        ->assertDontSee('Why it matters')
        ->assertSee('No participation opportunities yet.')
        ->assertSee('No extracted entities yet.');

    $response->assertSee('href="'.route('articles.source', $article).'"', false);
});

test('it renders the process timeline when items exist', function () {
    $city = City::factory()->create([
        'timezone' => 'America/Chicago',
    ]);

    $article = Article::factory()->create([
        'city_id' => $city->id,
        'title' => 'Timeline Article',
    ]);

    ArticleBody::factory()->create([
        'article_id' => $article->id,
        'cleaned_text' => str_repeat('Timeline details. ', 40),
    ]);

    ProcessTimelineItem::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'key' => 'proposal_submitted',
        'label' => 'Proposal Submitted',
        'status' => 'completed',
        'date' => Carbon::parse('2025-01-01 18:00:00', 'UTC'),
        'has_time' => false,
        'badge_text' => null,
        'note' => null,
        'position' => 1,
    ]);

    ProcessTimelineItem::create([
        'article_id' => $article->id,
        'city_id' => $city->id,
        'key' => 'public_comment_period',
        'label' => 'Public Comment Period',
        'status' => 'current',
        'date' => Carbon::parse('2025-01-10 18:00:00', 'UTC'),
        'has_time' => false,
        'badge_text' => 'OPEN NOW',
        'note' => 'Planning Commission',
        'position' => 2,
    ]);

    $response = $this->get(route('demo.articles.show', $article));

    $response->assertOk()
        ->assertSee('Where we are in the process')
        ->assertSee('Proposal Submitted')
        ->assertSee('Public Comment Period')
        ->assertSee('OPEN NOW')
        ->assertSee('Planning Commission')
        ->assertSee('Jan 1, 2025');
});
