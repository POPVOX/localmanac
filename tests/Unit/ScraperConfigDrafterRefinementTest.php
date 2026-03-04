<?php

use App\Services\Ingestion\Assistant\ScraperConfigAiRefiner;
use App\Services\Ingestion\Assistant\ScraperConfigDrafter;
use App\Services\Ingestion\Assistant\ScraperConfigHeuristicGenerator;

uses(Tests\TestCase::class);

it('always refines html drafts when html refinement is enabled', function () {
    config()->set('scraper-assistant.ai.refine_enabled', true);
    config()->set('scraper-assistant.ai.refine_html_always', true);
    config()->set('scraper-assistant.ai.refine_min_confidence', 0.75);

    $heuristic = [
        'profile' => 'generic_listing',
        'config' => [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'a[href]',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
            'best_effort' => true,
        ],
        'warnings' => [],
        'confidence' => 0.92,
    ];

    $refined = $heuristic;
    $refined['config']['list']['link_selector'] = '.flex-feature-feed h4.headline a[href^="/20"]';
    $refined['confidence'] = 0.97;

    $heuristicGenerator = Mockery::mock(ScraperConfigHeuristicGenerator::class);
    $heuristicGenerator->shouldReceive('generate')
        ->once()
        ->with('html', 'https://www.kwch.com/news/local/', '<html>local</html>')
        ->andReturn($heuristic);

    $aiRefiner = Mockery::mock(ScraperConfigAiRefiner::class);
    $aiRefiner->shouldReceive('refine')
        ->once()
        ->with('html', 'https://www.kwch.com/news/local/', '<html>local</html>', $heuristic)
        ->andReturn($refined);

    $draft = (new ScraperConfigDrafter($heuristicGenerator, $aiRefiner))
        ->draft('html', 'https://www.kwch.com/news/local/', '<html>local</html>');

    expect($draft['mode'])->toBe('ai_refined')
        ->and($draft['config']['list']['link_selector'])->toBe('.flex-feature-feed h4.headline a[href^="/20"]')
        ->and($draft['confidence'])->toBe(0.97);
});

it('only refines non-html drafts when confidence is below threshold', function () {
    config()->set('scraper-assistant.ai.refine_enabled', true);
    config()->set('scraper-assistant.ai.refine_html_always', true);
    config()->set('scraper-assistant.ai.refine_min_confidence', 0.75);

    $heuristic = [
        'profile' => 'rss',
        'config' => [
            'feed_url' => 'https://example.com/feed',
            'lang' => 'en',
            'max_items' => 50,
        ],
        'warnings' => [],
        'confidence' => 0.95,
    ];

    $heuristicGenerator = Mockery::mock(ScraperConfigHeuristicGenerator::class);
    $heuristicGenerator->shouldReceive('generate')
        ->once()
        ->with('rss', 'https://example.com/feed', '<xml/>')
        ->andReturn($heuristic);

    $aiRefiner = Mockery::mock(ScraperConfigAiRefiner::class);
    $aiRefiner->shouldNotReceive('refine');

    $draft = (new ScraperConfigDrafter($heuristicGenerator, $aiRefiner))
        ->draft('rss', 'https://example.com/feed', '<xml/>');

    expect($draft['mode'])->toBe('heuristic')
        ->and($draft['profile'])->toBe('rss')
        ->and($draft['confidence'])->toBe(0.95);
});

it('keeps specific heuristic selectors when ai refinement returns broad defaults', function () {
    config()->set('scraper-assistant.ai.refine_enabled', true);
    config()->set('scraper-assistant.ai.refine_html_always', true);
    config()->set('scraper-assistant.ai.refine_min_confidence', 0.75);

    $heuristic = [
        'profile' => 'generic_listing',
        'config' => [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => '.flex-feature-feed h4.headline a[href^="/20"]',
                'link_attr' => 'href',
                'max_links' => 25,
                'max_pages' => 5,
                'pagination_selector' => 'a[href*="/page/"]',
                'pagination_attr' => 'href',
            ],
            'article' => [
                'content_selector' => '.article-body',
                'remove_selectors' => ['script', 'style'],
            ],
            'best_effort' => true,
        ],
        'warnings' => [],
        'confidence' => 0.86,
    ];

    $aiRefined = [
        'profile' => 'generic_listing',
        'config' => [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'a[href]',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
            'best_effort' => true,
        ],
        'warnings' => [],
        'confidence' => 0.9,
    ];

    $heuristicGenerator = Mockery::mock(ScraperConfigHeuristicGenerator::class);
    $heuristicGenerator->shouldReceive('generate')
        ->once()
        ->with('html', 'https://www.kwch.com/news/local/', '<html>local</html>')
        ->andReturn($heuristic);

    $aiRefiner = Mockery::mock(ScraperConfigAiRefiner::class);
    $aiRefiner->shouldReceive('refine')
        ->once()
        ->with('html', 'https://www.kwch.com/news/local/', '<html>local</html>', $heuristic)
        ->andReturn($aiRefined);

    $draft = (new ScraperConfigDrafter($heuristicGenerator, $aiRefiner))
        ->draft('html', 'https://www.kwch.com/news/local/', '<html>local</html>');

    expect($draft['mode'])->toBe('ai_refined')
        ->and($draft['config']['list']['link_selector'])->toBe('.flex-feature-feed h4.headline a[href^="/20"]')
        ->and($draft['config']['list']['max_pages'])->toBe(5)
        ->and($draft['config']['list']['pagination_selector'])->toBe('a[href*="/page/"]')
        ->and($draft['config']['article']['content_selector'])->toBe('.article-body')
        ->and($draft['confidence'])->toBe(0.9);
});

it('reverts ai refined link selector when it has no matches in the listing html', function () {
    config()->set('scraper-assistant.ai.refine_enabled', true);
    config()->set('scraper-assistant.ai.refine_html_always', true);
    config()->set('scraper-assistant.ai.refine_min_confidence', 0.75);

    $html = <<<'HTML'
    <html>
        <body>
            <main>
                <article><h2><a href="/2026/03/01/story-one">Story One</a></h2></article>
                <article><h2><a href="/2026/03/02/story-two">Story Two</a></h2></article>
            </main>
        </body>
    </html>
    HTML;

    $heuristic = [
        'profile' => 'generic_listing',
        'config' => [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => 'article a',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
            'best_effort' => true,
        ],
        'warnings' => [],
        'confidence' => 0.64,
    ];

    $aiRefined = [
        'profile' => 'generic_listing',
        'config' => [
            'profile' => 'generic_listing',
            'list' => [
                'link_selector' => '.entry-title a',
                'link_attr' => 'href',
                'max_links' => 25,
            ],
            'article' => [
                'content_selector' => 'article',
                'remove_selectors' => ['script', 'style'],
            ],
            'best_effort' => true,
        ],
        'warnings' => [],
        'confidence' => 0.84,
    ];

    $heuristicGenerator = Mockery::mock(ScraperConfigHeuristicGenerator::class);
    $heuristicGenerator->shouldReceive('generate')
        ->once()
        ->with('html', 'https://wichitajournalism.org/latest-stories/', $html)
        ->andReturn($heuristic);

    $aiRefiner = Mockery::mock(ScraperConfigAiRefiner::class);
    $aiRefiner->shouldReceive('refine')
        ->once()
        ->with('html', 'https://wichitajournalism.org/latest-stories/', $html, $heuristic)
        ->andReturn($aiRefined);

    $draft = (new ScraperConfigDrafter($heuristicGenerator, $aiRefiner))
        ->draft('html', 'https://wichitajournalism.org/latest-stories/', $html);

    expect($draft['mode'])->toBe('ai_refined')
        ->and($draft['config']['list']['link_selector'])->toBe('article a')
        ->and($draft['warnings'])->toContain('AI link selector matched no listing links. Reverted to heuristic selector.');
});
