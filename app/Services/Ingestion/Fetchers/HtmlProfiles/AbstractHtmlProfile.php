<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventNormalizer;
use Symfony\Component\DomCrawler\Crawler;

abstract class AbstractHtmlProfile implements HtmlProfile
{
    public function __construct(
        protected readonly CalendarDateParser $dateParser,
        protected readonly EventNormalizer $normalizer,
    ) {}

    protected function textFor(Crawler $crawler, ?string $selector): string
    {
        if (! $selector) {
            return '';
        }

        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return '';
        }

        return trim($node->first()->text(''));
    }

    protected function attrFor(Crawler $crawler, ?string $selector, string $attr): string
    {
        if (! $selector) {
            return '';
        }

        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return '';
        }

        return trim((string) $node->first()->attr($attr));
    }

    protected function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}
