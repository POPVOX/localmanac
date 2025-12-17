<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
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

        // Prefer the main document container when parsing published Google Docs.
        // Google Docs "Publish to the web" pages usually render content inside #contents.
        $root = $crawler->filter('#contents');

        // Fallbacks for non-Google-doc HTML variants.
        if ($root->count() === 0) {
            $root = $crawler->filter('main, article');
        }

        if ($root->count() === 0) {
            $root = $crawler->filter('body');
        }

        $parts = [];

        // Extract from common block elements.
        foreach (['h1', 'h2', 'h3', 'p', 'li'] as $selector) {
            $root->filter($selector)->each(function (Crawler $node) use (&$parts) {
                $text = $this->normalizeWhitespace($node->text(''));

                if ($text !== '') {
                    $parts[] = $text;
                }
            });
        }

        // Extract table text (Google Docs often uses tables for agendas/roll calls).
        $root->filter('table')->each(function (Crawler $table) use (&$parts) {
            $rows = $table->filter('tr')->each(function (Crawler $tr) {
                $cells = $tr->filter('th,td')->each(function (Crawler $cell) {
                    return $this->normalizeWhitespace($cell->text(''));
                });

                $cells = array_values(array_filter($cells, fn (string $c) => $c !== ''));

                return implode(' | ', $cells);
            });

            $rows = array_values(array_filter($rows, fn (string $r) => $r !== ''));

            if (! empty($rows)) {
                $parts[] = implode("\n", $rows);
            }
        });

        $parts = array_values(array_filter($parts, fn (string $t) => $t !== ''));

        $text = trim(implode("\n\n", $parts));

        // If DomCrawler-based extraction yields nothing (common when pages are heavy on scripts),
        // fall back to a lightweight HTML->text conversion.
        if ($text === '') {
            $text = $this->fallbackHtmlToText($html);
        }

        return $text;
    }

    private function fallbackHtmlToText(string $html): string
    {
        // Preserve some structure before stripping tags.
        $replacements = [
            "</p>" => "\n\n",
            "</li>" => "\n",
            "<br>" => "\n",
            "<br/>" => "\n",
            "<br />" => "\n",
            "</h1>" => "\n\n",
            "</h2>" => "\n\n",
            "</h3>" => "\n\n",
            "</tr>" => "\n",
        ];

        $value = str_ireplace(array_keys($replacements), array_values($replacements), $html);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace while keeping newlines.
        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = preg_replace("/[ \t\f\v]+/", " ", $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }

    private function extractPublishedAt(string $html): ?Carbon
    {
        $candidate = null;

        if (preg_match('/Date:\s*([A-Z][a-z]+\s+\d{1,2},\s+\d{4})/', $html, $matches)) {
            $candidate = $matches[1];
        } else {
            // Sometimes the visible text contains the Date label but the raw HTML is structured differently.
            $text = $this->fallbackHtmlToText($html);
            if (preg_match('/Date:\s*([A-Z][a-z]+\s+\d{1,2},\s+\d{4})/', $text, $m)) {
                $candidate = $m[1];
            }
        }

        if (! $candidate) {
            return null;
        }

        try {
            return Carbon::parse($candidate);
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
