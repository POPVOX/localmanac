<?php

use App\Services\Ingestion\Assistant\ScraperConfigHeuristicGenerator;

uses(Tests\TestCase::class);

it('detects arc fusion listing markup and returns focused generic listing selectors', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <div id="fusion-app">
                <div class="flex-feature-feed">
                    <h4 class="headline"><a href="/2026/03/03/story-one/">Story One</a></h4>
                    <h4 class="headline"><a href="/2026/03/03/story-two/">Story Two</a></h4>
                </div>
            </div>
        </body>
    </html>
    HTML;

    $result = app(ScraperConfigHeuristicGenerator::class)
        ->generate('html', 'https://www.kwch.com/news/local/', $html);

    expect($result['profile'])->toBe('generic_listing')
        ->and($result['config']['profile'])->toBe('generic_listing')
        ->and($result['config']['list']['link_selector'])->toBe('.flex-feature-feed h4.headline a[href^="/20"]')
        ->and($result['config']['article']['content_selector'])->toBe('.article-body')
        ->and($result['confidence'])->toBe(0.86);
});

it('prefers wordpress-style title and body selectors over broad defaults', function () {
    $html = <<<'HTML'
    <html>
        <body>
            <main>
                <article>
                    <h2 class="entry-title"><a href="/2026/03/01/story-one">Story One</a></h2>
                    <div class="entry-content"><p>Summary one.</p></div>
                </article>
                <article>
                    <h2 class="entry-title"><a href="/2026/03/02/story-two">Story Two</a></h2>
                    <div class="entry-content"><p>Summary two.</p></div>
                </article>
            </main>
        </body>
    </html>
    HTML;

    $result = app(ScraperConfigHeuristicGenerator::class)
        ->generate('html', 'https://example.com/news', $html);

    expect($result['profile'])->toBe('generic_listing')
        ->and($result['config']['list']['link_selector'])->toBe('.entry-title a')
        ->and($result['config']['article']['content_selector'])->toBe('.entry-content')
        ->and($result['confidence'])->toBe(0.72)
        ->and($result['warnings'])->toBe([]);
});

it('detects wix blog listings and prioritizes post permalinks', function () {
    $html = <<<'HTML'
    <html lang="en">
        <head>
            <meta name="generator" content="Wix.com Website Builder">
        </head>
        <body>
            <main>
                <a href="/blog/categories/english">English</a>
                <a href="/post/story-one">Story One</a>
                <a href="/post/story-two">Story Two</a>
            </main>
        </body>
    </html>
    HTML;

    $result = app(ScraperConfigHeuristicGenerator::class)
        ->generate('html', 'https://example.com/blog/categories/english', $html);

    expect($result['profile'])->toBe('generic_listing')
        ->and($result['config']['list']['link_selector'])->toBe('main a[href*="/post/"]')
        ->and($result['config']['list']['max_pages'])->toBe(5)
        ->and($result['config']['list']['pagination_selector'])->toBe('a[href*="/page/"]')
        ->and($result['config']['article']['content_selector'])->toBe('main')
        ->and($result['confidence'])->toBe(0.84)
        ->and($result['warnings'])->toBe([]);
});
