<?php

use Symfony\Component\DomCrawler\Crawler;

test('key details render as a semantic list without an empty second column', function () {
    $html = view('components.article-explainer-lists', [
        'keyDetails' => [
            ['label' => null, 'value' => null, 'text' => 'Residents are the primary audience for these health tips.'],
            ['label' => 'Recommendation:', 'value' => 'Wash hands and store leftovers safely.', 'text' => null],
        ],
        'whatToWatch' => [],
    ])->render();
    $crawler = new Crawler($html);

    expect($crawler->filter('[data-testid="explainer-lists"][data-layout="single"]')->count())->toBe(1)
        ->and($crawler->filter('ul[data-testid="key-details-list"]')->count())->toBe(1)
        ->and($crawler->filter('ul[data-testid="key-details-list"] > li')->count())->toBe(2)
        ->and($crawler->filter('[data-testid="what-to-watch-list"]')->count())->toBe(0)
        ->and($crawler->filter('section[aria-labelledby="key-details-heading"]')->count())->toBe(1);
});

test('key details and watch items split into two columns when both exist', function () {
    $html = view('components.article-explainer-lists', [
        'keyDetails' => [
            ['label' => null, 'value' => null, 'text' => 'One key detail.'],
        ],
        'whatToWatch' => [
            ['label' => null, 'value' => null, 'text' => 'One thing to watch.'],
        ],
    ])->render();
    $crawler = new Crawler($html);

    expect($crawler->filter('[data-testid="explainer-lists"][data-layout="split"]')->count())->toBe(1)
        ->and($crawler->filter('ul[data-testid="key-details-list"] > li')->count())->toBe(1)
        ->and($crawler->filter('ul[data-testid="what-to-watch-list"] > li')->count())->toBe(1);
});
