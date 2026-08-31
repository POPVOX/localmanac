<?php

use App\Models\ChatSource;
use App\Models\ChatSourceChunk;
use App\Models\ChatSourcePage;
use App\Models\City;
use App\Services\Chat\ChatSourceRetriever;
use Carbon\CarbonImmutable;
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

it('prioritizes focused procedural permit evidence over generic faq content', function () {
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

    $permitSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Building Permit Center',
        'source_url' => 'https://example.com/demolition-permit',
        'is_active' => true,
    ]);

    $faqSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita FAQ',
        'source_url' => 'https://example.com/faq',
        'is_active' => true,
    ]);

    $permitPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $permitSource->id,
        'url' => 'https://example.com/demolition-permit',
        'canonical_url' => 'https://example.com/demolition-permit',
        'title' => 'Demolition Permit Application',
        'content_text' => 'Before you apply for a demolition permit, submit the required contractor documents and schedule an inspection through the permit portal.',
        'content_length' => 133,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $permitPage->id,
        'chunk_index' => 0,
        'content' => 'Before you apply for a demolition permit, submit the required contractor documents and schedule an inspection through the permit portal.',
        'content_length' => 133,
    ]);

    $faqPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $faqSource->id,
        'url' => 'https://example.com/faq',
        'canonical_url' => 'https://example.com/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'FAQ. All content. Boards and committees. General permit questions are answered here.',
        'content_length' => 84,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $faqPage->id,
        'chunk_index' => 0,
        'content' => 'FAQ. All content. Boards and committees. General permit questions are answered here.',
        'content_length' => 84,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(
        collect([$permitSource, $faqSource]),
        'How do I get a demolition permit and schedule an inspection?'
    );

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['source_url'])->toBe('https://example.com/demolition-permit')
        ->and($result['evidence'][0]['snippet'])->toContain('Before you apply for a demolition permit');
});

it('demotes unrelated permit pages when a specific permit type is requested', function () {
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

    $permitSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Licenses & Permits',
        'source_url' => 'https://example.com/licenses-permits',
        'is_active' => true,
    ]);

    $applyForSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Apply For',
        'source_url' => 'https://example.com/apply-for',
        'is_active' => true,
    ]);

    $burnPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $permitSource->id,
        'url' => 'https://example.com/burn-permit',
        'canonical_url' => 'https://example.com/burn-permit',
        'title' => 'Burn Permits',
        'content_text' => 'Burn permit rules, open burn requirements, and fire department guidance.',
        'content_length' => 74,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $burnPage->id,
        'chunk_index' => 0,
        'content' => 'Burn permit rules, open burn requirements, and fire department guidance.',
        'content_length' => 74,
    ]);

    $trafficPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $permitSource->id,
        'url' => 'https://example.com/traffic-permit',
        'canonical_url' => 'https://example.com/traffic-permit',
        'title' => 'Traffic, Street & Construction Permitting',
        'content_text' => 'Street closure permits, traffic control plans, and right-of-way construction requirements.',
        'content_length' => 96,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $trafficPage->id,
        'chunk_index' => 0,
        'content' => 'Street closure permits, traffic control plans, and right-of-way construction requirements.',
        'content_length' => 96,
    ]);

    $demolitionPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $applyForSource->id,
        'url' => 'https://example.com/demolition-permit',
        'canonical_url' => 'https://example.com/demolition-permit',
        'title' => 'Demolition Permit Application',
        'content_text' => 'Demolition permit applications require contractor documents, approval, and an inspection after approval.',
        'content_length' => 104,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $demolitionPage->id,
        'chunk_index' => 0,
        'content' => 'Demolition permit applications require contractor documents, approval, and an inspection after approval.',
        'content_length' => 104,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(
        collect([$permitSource, $applyForSource]),
        'How do I get a demolition permit?'
    );

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['source_url'])->toBe('https://example.com/demolition-permit')
        ->and($result['evidence'][0]['title'])->toBe('Demolition Permit Application');
});

it('demotes topical but non procedural chunks for permit process questions', function () {
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

    $permitSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Apply For',
        'source_url' => 'https://example.com/apply-for',
        'is_active' => true,
    ]);

    $landfillSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Brooks Landfill',
        'source_url' => 'https://example.com/brooks-landfill',
        'is_active' => true,
    ]);

    $faqSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita FAQ',
        'source_url' => 'https://example.com/faq',
        'is_active' => true,
    ]);

    $permitPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $permitSource->id,
        'url' => 'https://example.com/demolition-permit',
        'canonical_url' => 'https://example.com/demolition-permit',
        'title' => 'Demolition Permit Application',
        'content_text' => 'Submit the demolition permit application, required contractor documents, and inspection request through the permit portal.',
        'content_length' => 118,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $permitPage->id,
        'chunk_index' => 0,
        'content' => 'Submit the demolition permit application, required contractor documents, and inspection request through the permit portal.',
        'content_length' => 118,
    ]);

    $landfillPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $landfillSource->id,
        'url' => 'https://example.com/brooks-landfill',
        'canonical_url' => 'https://example.com/brooks-landfill',
        'title' => 'Brooks Landfill',
        'content_text' => 'Construction and demolition debris may be taken to Brooks Landfill. Call 316-350-3225 for disposal rules.',
        'content_length' => 103,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $landfillPage->id,
        'chunk_index' => 0,
        'content' => 'Construction and demolition debris may be taken to Brooks Landfill. Call 316-350-3225 for disposal rules.',
        'content_length' => 103,
    ]);

    $faqPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $faqSource->id,
        'url' => 'https://example.com/faq',
        'canonical_url' => 'https://example.com/faq',
        'title' => 'Frequently Asked Questions',
        'content_text' => 'For demolition permit questions, contact city staff for general guidance.',
        'content_length' => 69,
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $faqPage->id,
        'chunk_index' => 0,
        'content' => 'For demolition permit questions, contact city staff for general guidance.',
        'content_length' => 69,
    ]);

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(
        collect([$permitSource, $landfillSource, $faqSource]),
        'How do I get a demolition permit?'
    );

    expect($result['evidence'])->not->toBeEmpty()
        ->and($result['evidence'][0]['source_url'])->toBe('https://example.com/demolition-permit');
});

it('prioritizes recent updates over generic pages for aggregation queries', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-24 15:00:00', 'UTC'));

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

    $genericSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Current Projects',
        'source_url' => 'https://example.com/current-projects',
        'is_active' => true,
    ]);

    $updatesSource = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Wichita Updates',
        'source_url' => 'https://example.com/updates',
        'is_active' => true,
    ]);

    $genericPage = ChatSourcePage::factory()->create([
        'chat_source_id' => $genericSource->id,
        'url' => 'https://example.com/current-projects',
        'canonical_url' => 'https://example.com/current-projects',
        'title' => 'Current Projects',
        'content_text' => 'This week residents can review new permits, new licenses, and current project pages.',
        'content_length' => 83,
        'fetched_at' => CarbonImmutable::parse('2026-03-24 14:00:00', 'UTC'),
        'updated_at' => CarbonImmutable::parse('2026-03-24 14:00:00', 'UTC'),
        'created_at' => CarbonImmutable::parse('2026-03-24 14:00:00', 'UTC'),
    ]);

    ChatSourceChunk::factory()->create([
        'chat_source_page_id' => $genericPage->id,
        'chunk_index' => 0,
        'content' => 'This week residents can review new permits, new licenses, and current project pages.',
        'content_length' => 83,
    ]);

    $recentPages = [
        [
            'url' => 'https://example.com/updates/service-alert-march-24',
            'title' => 'Service Alert Update - March 24, 2026',
            'content' => 'Announcement posted March 24, 2026. New service alert this week for water service work.',
            'timestamp' => '2026-03-24 13:00:00',
        ],
        [
            'url' => 'https://example.com/updates/rezoning-march-23',
            'title' => 'Rezoning Update - March 23, 2026',
            'content' => 'Update posted March 23, 2026. New rezoning filing this week at Central and Oliver.',
            'timestamp' => '2026-03-23 11:00:00',
        ],
        [
            'url' => 'https://example.com/updates/project-march-21',
            'title' => 'Project Approval Announcement - March 21, 2026',
            'content' => 'Announcement published March 21, 2026. New project approval this week for a downtown site.',
            'timestamp' => '2026-03-21 09:00:00',
        ],
    ];

    foreach ($recentPages as $pageData) {
        $page = ChatSourcePage::factory()->create([
            'chat_source_id' => $updatesSource->id,
            'url' => $pageData['url'],
            'canonical_url' => $pageData['url'],
            'title' => $pageData['title'],
            'content_text' => $pageData['content'],
            'content_length' => strlen($pageData['content']),
            'fetched_at' => CarbonImmutable::parse($pageData['timestamp'], 'UTC'),
            'updated_at' => CarbonImmutable::parse($pageData['timestamp'], 'UTC'),
            'created_at' => CarbonImmutable::parse($pageData['timestamp'], 'UTC'),
        ]);

        ChatSourceChunk::factory()->create([
            'chat_source_page_id' => $page->id,
            'chunk_index' => 0,
            'content' => $pageData['content'],
            'content_length' => strlen($pageData['content']),
        ]);
    }

    $retriever = app(ChatSourceRetriever::class);
    $result = $retriever->retrieve(
        collect([$genericSource, $updatesSource]),
        "What's new this week?"
    );

    $urls = collect($result['evidence'])->pluck('source_url')->all();

    expect($result['evidence'])->toHaveCount(4)
        ->and($urls)->toContain('https://example.com/updates/service-alert-march-24')
        ->and($urls)->toContain('https://example.com/updates/rezoning-march-23')
        ->and($urls)->toContain('https://example.com/updates/project-march-21');

    CarbonImmutable::setTestNow();
});

it('uses retrieval v2 fusion and source diversity as one integrated pipeline', function () {
    config()->set('scout.driver', 'collection');
    config()->set('chat.vector_enabled', false);
    config()->set('chat.fts_enabled', true);
    config()->set('chat.reranking_enabled', false);
    config()->set('chat.retrieval_v2_enabled', true);
    config()->set('chat.retrieval_chunk_limit', 8);
    config()->set('chat.retrieval_neighbor_window', 0);
    config()->set('chat.retrieval_max_evidence', 3);
    config()->set('chat.retrieval_max_evidence_per_source', 1);
    config()->set('chat.retrieval_context_token_budget', 1000);

    $city = City::factory()->create();
    $parks = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'Parks Department',
        'is_active' => true,
    ]);
    $services = ChatSource::factory()->create([
        'city_id' => $city->id,
        'name' => 'City Services',
        'is_active' => true,
    ]);

    foreach ([
        ['source' => $parks, 'url' => 'https://parks.test/one', 'content' => 'Park services include shelter reservations.'],
        ['source' => $parks, 'url' => 'https://parks.test/two', 'content' => 'Park services include recreation programs.'],
        ['source' => $services, 'url' => 'https://city.test/services', 'content' => 'City park services are available online.'],
    ] as $index => $data) {
        $page = ChatSourcePage::factory()->create([
            'chat_source_id' => $data['source']->id,
            'url' => $data['url'],
            'canonical_url' => $data['url'],
            'content_text' => $data['content'],
        ]);

        ChatSourceChunk::factory()->create([
            'chat_source_page_id' => $page->id,
            'chunk_index' => $index,
            'content' => $data['content'],
            'content_length' => strlen($data['content']),
        ]);
    }

    $result = app(ChatSourceRetriever::class)->retrieve(
        collect([$parks, $services]),
        'park services',
        $city->id,
    );

    expect($result['evidence'])->toHaveCount(3)
        ->and($result['evidence'][0]['source_url'])->toBe('https://parks.test/one')
        ->and($result['evidence'][1]['source_url'])->toBe('https://city.test/services')
        ->and($result['evidence'][2]['source_url'])->toBe('https://parks.test/two');
});
