<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class DocumentersFetcher
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(Scraper $scraper): array
    {
        $sourceUrl = $scraper->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('Scraper source_url must exist');
        }

        $listConfig = $scraper->config['list'] ?? null;

        if (! is_array($listConfig)) {
            throw new InvalidArgumentException('Scraper list config must exist');
        }

        $linkSelector = $listConfig['link_selector'] ?? null;

        if (! $linkSelector) {
            throw new InvalidArgumentException('Scraper list link_selector must exist');
        }

        $linkAttr = $listConfig['link_attr'] ?? 'href';
        $maxLinks = (int) ($listConfig['max_links'] ?? 50);

        $listingResponse = $this->httpClient()->get($sourceUrl);

        if (! $listingResponse->successful()) {
            throw new InvalidArgumentException('Failed to fetch listing page');
        }

        $links = $this->extractDocLinks($listingResponse->body(), $linkSelector, $linkAttr, $maxLinks);
        $items = [];

        foreach ($links as $url) {
            $docResponse = $this->httpClient()->get($url);

            if (! $docResponse->successful()) {
                continue;
            }

            $rawHtml = $docResponse->body();
            $cleanedText = $this->extractCleanedText($rawHtml);

            if ($cleanedText === '') {
                continue;
            }

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $scraper->name.' — Notes',
                'published_at' => $this->extractPublishedAt($rawHtml),
                'summary' => null,
                'body' => [
                    'raw_html' => $rawHtml,
                    'cleaned_text' => $cleanedText,
                ],
                'source' => [
                    'source_type' => 'html',
                    'source_url' => $url,
                ],
                'content_hash' => sha1($cleanedText),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, string>
     */
    private function extractDocLinks(string $html, string $linkSelector, string $linkAttr, int $maxLinks): array
    {
        $crawler = new Crawler($html, 'https://wichitadocumenters.org');

        $links = $crawler->filter($linkSelector)->each(function (Crawler $node) use ($linkAttr) {
            $href = $node->attr($linkAttr) ?? '';
            $href = trim($href);

            if ($href === '') {
                return null;
            }

            return $this->normalizeUrl($href);
        });

        $links = array_values(array_unique(array_filter($links, function (?string $url) {
            return is_string($url) && Str::contains($url, 'docs.google.com');
        })));

        if ($maxLinks > 0) {
            $links = array_slice($links, 0, $maxLinks);
        }

        return $links;
    }

    private function extractCleanedText(string $html): string
    {
        $crawler = new Crawler($html);

        $parts = $crawler->filter('body p, body h1, body h2, body h3, body li')->each(function (Crawler $node) {
            return $this->normalizeWhitespace($node->text());
        });

        $parts = array_filter($parts, fn (string $text) => $text !== '');

        return implode("\n\n", $parts);
    }

    private function extractPublishedAt(string $html): ?Carbon
    {
        if (! preg_match('/Date:\\s*([A-Z][a-z]+\\s+\\d{1,2},\\s+\\d{4})/', $html, $matches)) {
            return null;
        }

        try {
            return Carbon::parse($matches[1]);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\\s+/', ' ', $value) ?? '');
    }

    private function normalizeUrl(string $url): string
    {
        if (Str::startsWith($url, '//')) {
            return 'https:'.$url;
        }

        if (! Str::startsWith($url, ['http://', 'https://'])) {
            return 'https://wichitadocumenters.org/'.ltrim($url, '/');
        }

        return $url;
    }

    private function httpClient()
    {
        return Http::timeout(20)
            ->retry(2, 250)
            ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0']);
    }
}
