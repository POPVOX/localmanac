<?php

use App\Models\City;
use App\Models\Scraper;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Ingestion\Assistant\ScraperConfigAiRefiner;
use App\Services\Ingestion\Assistant\ScraperConfigDrafter;
use App\Services\Ingestion\Fetchers\DocumentersFetcher;
use App\Services\Ingestion\Fetchers\GenericListingFetcher;
use Illuminate\Support\Facades\Http;

it('extracts Finalsite news instead of navigation documents, forms or calendar entries', function (bool $hasDate) {
    Http::preventStrayRequests();
    config()->set('scraper-assistant.ai.refine_enabled', true);
    config()->set('chat.crawl_min_text_chars', 0);
    $this->mock(ScraperConfigAiRefiner::class)->shouldNotReceive('refine');
    $home = '<title>School Home</title><nav>'
        .'<a href="https://docs.google.com/document/d/plan/edit">Strategic plan</a>'
        .'<a href="https://docs.google.com/forms/d/form/viewform">Registration</a></nav>'
        .'<section class="fsPostElement fsList"><article data-post-id="123"><div class="fsTitle">'
        .'<a class="fsPostLink" href="/news/update">School update</a></div>'
        .($hasDate ? '<div class="fsDateTime"><time datetime="2026-10-09T16:11:00-04:00">Oct 9</time></div>' : '')
        .'</article></section>'
        .'<section class="fsCalendar"><article><div class="fsTitle"><a href="#">Board meeting</a></div></article></section>';
    $article = '<title>Page title</title><nav>Unrelated navigation</nav><div class="fsPostElement fsPost">'
        .'<article><div class="fsTitle">School update</div><div class="fsBody"><p>'
        .str_repeat('Students planted a new school garden. ', 30).'</p></div></article></div>'
        .'<section class="fsPostElement fsList"><time datetime="2026-10-19">Related post</time></section>';
    Http::fake([
        'https://school.example/' => Http::response($home, 200),
        'https://school.example/news/update' => Http::response($article, 200),
    ]);
    $draft = app(ScraperConfigDrafter::class)->draft('html', 'https://school.example/', $home);
    $source = new Scraper([
        'city_id' => 1,
        'type' => 'html',
        'source_url' => 'https://school.example/',
        'config' => $draft['config'],
    ]);
    $source->setRelation('city', new City(['timezone' => 'America/New_York']));

    $items = app(GenericListingFetcher::class)->fetch($source);

    expect($draft['profile'])->toBe('generic_listing')
        ->and($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('School update')
        ->and($items[0]['source']['source_url'])->toBe('https://school.example/news/update')
        ->and($items[0]['published_at']?->toIso8601String())->toBe($hasDate ? '2026-10-09T16:11:00-04:00' : null)
        ->and($items[0]['body']['cleaned_text'])->toContain('Students planted')
        ->not->toContain('Unrelated navigation');
    Http::assertSentCount(2);
})->with([true, false]);

it('does not identify ordinary sites as Documenters just because they link to Google Docs', function () {
    config()->set('scraper-assistant.ai.refine_enabled', false);
    $draft = app(ScraperConfigDrafter::class)->draft('html', 'https://school.example/',
        '<title>School Home</title><nav><a href="https://docs.google.com/document/d/plan/edit">Plan</a></nav>'
        .'<article><h2><a href="/news/update">News</a></h2></article>');

    expect($draft['profile'])->toBe('generic_listing');
});

it('rejects forms, spreadsheets and spoofed document hosts even in existing Documenters configs', function () {
    $source = new Scraper([
        'city_id' => 1,
        'type' => 'html',
        'source_url' => 'https://school.example/',
        'config' => ['profile' => 'documenters', 'list' => ['link_selector' => 'a[href*="docs.google.com"]']],
    ]);
    $source->setRelation('city', new City(['timezone' => 'America/New_York']));
    $fetcher = Mockery::mock(PageFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->with('https://school.example/', 'http')->andReturn([
        'url' => 'https://school.example/', 'status_code' => 200, 'content_type' => 'text/html', 'renderer' => 'http',
        'body' => '<a href="https://docs.google.com/forms/d/form/viewform">Form</a>'
            .'<a href="https://docs.google.com/spreadsheets/d/sheet/edit">Sheet</a>'
            .'<a href="https://docs.google.com.evil.example/document/d/fake/pub">Fake</a>',
    ]);

    expect((new DocumentersFetcher($fetcher))->fetch($source))->toBe([]);
});
