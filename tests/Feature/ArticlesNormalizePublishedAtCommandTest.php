<?php

use App\Enums\ArticlePublishedPrecision;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Models\Scraper;

function makeArticleNormalizationCity(): City
{
    return City::create([
        'name' => 'Wichita',
        'slug' => 'wichita',
        'timezone' => 'America/Chicago',
    ]);
}

function makeArticleNormalizationScraper(City $city, string $type, string $profile, string $slug): Scraper
{
    return Scraper::create([
        'city_id' => $city->id,
        'name' => ucfirst($slug),
        'slug' => $slug,
        'type' => $type,
        'source_url' => 'https://example.com/'.$slug,
        'is_enabled' => true,
        'config' => [
            'profile' => $profile,
            'article' => [
                'content_selector' => 'main',
            ],
        ],
    ]);
}

it('repairs generic listing article timestamps from the source page', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'generic_listing', 'the-sunflower');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Student senate recommends change',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/story',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://example.com/story',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => <<<'HTML'
            <html>
                <body>
                    <div class="sno-story-byline">
                        <span class="time-wrapper">March 13, 2026</span>
                    </div>
                    <main>
                        <p>Body text.</p>
                    </main>
                </body>
            </html>
            HTML,
        'cleaned_text' => 'Body text.',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'the-sunflower',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-13T05:00:00+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});

it('repairs documenters article timestamps from stored raw html', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'wichitadocumenters', 'wichita-documenters');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Wichita Documenters — Notes',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://docs.google.com/document/d/example',
        'published_at' => '2025-09-16 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_html' => '<html><body><p>Date: Sept. 16, 2025</p></body></html>',
        'cleaned_text' => 'Date: Sept. 16, 2025',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'wichita-documenters',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});

it('backfills rss article precision without changing published_at', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'rss', 'unused', 'kwch-rss');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'RSS article',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/rss-story',
        'published_at' => '2026-03-13 14:50:18+00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'kwch-rss',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-13T14:50:18+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::DateTime);
});

it('prints a per-scraper normalization summary', function () {
    $city = makeArticleNormalizationCity();
    $sunflower = makeArticleNormalizationScraper($city, 'html', 'generic_listing', 'the-sunflower');
    $voice = makeArticleNormalizationScraper($city, 'html', 'generic_listing', 'the-voice-wichita');

    $resolvedArticle = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $sunflower->id,
        'title' => 'Resolved article',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/resolved',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    $unresolvedArticle = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $voice->id,
        'title' => 'Unresolved article',
        'summary' => 'Summary',
        'status' => 'published',
        'content_type' => 'html',
        'canonical_url' => 'https://example.com/unresolved',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')
        ->whereIn('id', [$resolvedArticle->id, $unresolvedArticle->id])
        ->update([
            'created_at' => '2026-03-10 12:00:00',
            'updated_at' => '2026-03-10 12:00:00',
        ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $resolvedArticle->id,
        'source_url' => 'https://example.com/resolved',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $unresolvedArticle->id,
        'source_url' => 'https://example.com/unresolved',
        'source_type' => 'html',
        'accessed_at' => now(),
    ]);

    ArticleBody::create([
        'article_id' => $resolvedArticle->id,
        'raw_html' => <<<'HTML'
            <html>
                <body>
                    <div class="sno-story-byline">
                        <span class="time-wrapper">March 13, 2026</span>
                    </div>
                    <main>
                        <p>Body text.</p>
                    </main>
                </body>
            </html>
            HTML,
        'cleaned_text' => 'Body text.',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    ArticleBody::create([
        'article_id' => $unresolvedArticle->id,
        'raw_html' => '<html><body><main><p>No publish date here.</p></main></body></html>',
        'cleaned_text' => 'No publish date here.',
        'lang' => 'en',
        'extracted_at' => now(),
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--city' => 'wichita',
        '--before' => '2026-03-15 00:00:00+00',
    ])->assertSuccessful()
        ->expectsOutputToContain('By scraper:')
        ->expectsOutputToContain('The-sunflower (the-sunflower): scanned=1 resolved=1 needs_update=1 updated=0 unresolved=0')
        ->expectsOutputToContain('The-voice-wichita (the-voice-wichita): scanned=1 resolved=0 needs_update=0 updated=0 unresolved=1')
        ->expectsOutputToContain('Unresolved samples:')
        ->expectsOutputToContain('[2] The-voice-wichita (the-voice-wichita)')
        ->expectsOutputToContain('title: Unresolved article')
        ->expectsOutputToContain('url: https://example.com/unresolved')
        ->expectsOutputToContain('snippet: No publish date here.');
});

it('repairs legal notice archive pdf article timestamps from extracted pdf text', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'wichita_archive_pdf_list', 'legal-notices');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => '458-2022-085515_LegalNotice (PDF)',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://www.wichita.gov/Archive.aspx?ADID=12345',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleSource::create([
        'city_id' => $city->id,
        'article_id' => $article->id,
        'source_url' => 'https://www.wichita.gov/Archive.aspx?ADID=12345',
        'source_type' => 'pdf',
        'source_uid' => '12345',
        'accessed_at' => now(),
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_text' => implode("\n", [
            'PROJ # 458-2022-085515',
            'Published on the City\'s Website on Friday, January 30, 2026',
            'SEALED PROPOSALS',
        ]),
        'cleaned_text' => implode("\n", [
            'PROJ # 458-2022-085515',
            'Published on the City\'s Website on Friday, January 30, 2026',
            'SEALED PROPOSALS',
        ]),
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'legal-notices',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1')
        ->expectsOutputToContain('Legal-notices (legal-notices): scanned=1 resolved=1 needs_update=1 updated=1 unresolved=0');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-01-30T06:00:00+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});

it('repairs legal notice archive pdf timestamps from Wichita.gov numeric publication lines', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'wichita_archive_pdf_list', 'legal-notices');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Abatement of the property located at 1832 N Grove',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://www.wichita.gov/Archive.aspx?ADID=14404',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_text' => 'Org Code # 10022741 City of Wichita NOTICE OF LEGAL PUBLICATION NNE2026-00240 Published Wichita.gov website on 3/4/2026.',
        'cleaned_text' => 'Org Code # 10022741 City of Wichita NOTICE OF LEGAL PUBLICATION NNE2026-00240 Published Wichita.gov website on 3/4/2026.',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'legal-notices',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-04T06:00:00+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});

it('repairs legal notice archive pdf timestamps from Wichita legal notices publication listings', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'wichita_archive_pdf_list', 'legal-notices');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => '(Published At Wichita.gov/Legalnotices On February 27',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://www.wichita.gov/Archive.aspx?ADID=14297',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_text' => '(Published at Wichita.gov/LegalNotices on February 27, 2026, and March 6, 2026) NOTICE OF PUBLIC HEARING REGARDING PROPOSED FIRST AMENDMENT',
        'cleaned_text' => '(Published at Wichita.gov/LegalNotices on February 27, 2026, and March 6, 2026) NOTICE OF PUBLIC HEARING REGARDING PROPOSED FIRST AMENDMENT',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'legal-notices',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-02-27T06:00:00+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});

it('repairs legal notice archive pdf timestamps from affidavit publication language', function () {
    $city = makeArticleNormalizationCity();
    $scraper = makeArticleNormalizationScraper($city, 'html', 'wichita_archive_pdf_list', 'legal-notices');

    $article = Article::create([
        'city_id' => $city->id,
        'scraper_id' => $scraper->id,
        'title' => 'Abatement of the property at 2111 S Washington',
        'summary' => null,
        'status' => 'published',
        'content_type' => 'pdf',
        'canonical_url' => 'https://www.wichita.gov/Archive.aspx?ADID=14239',
        'published_at' => '2026-03-13 00:00:00',
    ]);

    \Illuminate\Support\Facades\DB::table('articles')->where('id', $article->id)->update([
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);

    ArticleBody::create([
        'article_id' => $article->id,
        'raw_text' => 'WICHITA AFFIDAVIT OF PUBLICATION State of Kansas, Sedgwick County, ss: Shinita Rice, City Clerk being duly sworn states this notice was published on such website beginning on the 4th day of March, 2026.',
        'cleaned_text' => 'WICHITA AFFIDAVIT OF PUBLICATION State of Kansas, Sedgwick County, ss: Shinita Rice, City Clerk being duly sworn states this notice was published on such website beginning on the 4th day of March, 2026.',
        'lang' => 'en',
        'extracted_at' => now(),
        'extraction_status' => 'success',
    ]);

    $this->artisan('articles:normalize-published-at', [
        '--scraper' => 'legal-notices',
        '--before' => '2026-03-15 00:00:00+00',
        '--apply' => true,
    ])->assertSuccessful()
        ->expectsOutputToContain('resolved: 1')
        ->expectsOutputToContain('updated: 1');

    expect($article->fresh()?->published_at?->toAtomString())->toBe('2026-03-04T06:00:00+00:00')
        ->and($article->fresh()?->published_precision)->toBe(ArticlePublishedPrecision::Date);
});
