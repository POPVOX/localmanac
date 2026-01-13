<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class GenericListingFetcher
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(Scraper $scraper): array
    {
        if ($scraper->type !== 'html') {
            throw new InvalidArgumentException('Scraper type must be html');
        }

        $config = $scraper->config ?? [];
        $profile = Arr::get($config, 'profile');

        if ($profile !== 'generic_listing') {
            throw new InvalidArgumentException('Scraper profile must be generic_listing');
        }

        $sourceUrl = $scraper->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('Scraper source_url must exist');
        }

        $listConfig = Arr::get($config, 'list');
        $articleConfig = Arr::get($config, 'article');

        if (! is_array($listConfig) || ! is_array($articleConfig)) {
            throw new InvalidArgumentException('Scraper list/article config must exist');
        }

        $linkSelector = Arr::get($listConfig, 'link_selector');

        if (! $linkSelector) {
            throw new InvalidArgumentException('Scraper list link_selector must exist');
        }

        $linkAttr = Arr::get($listConfig, 'link_attr', 'href');
        $maxLinks = (int) Arr::get($listConfig, 'max_links', 50);

        $contentSelector = Arr::get($articleConfig, 'content_selector');

        if (! $contentSelector) {
            throw new InvalidArgumentException('Scraper article content_selector must exist');
        }

        $removeSelectors = Arr::get($articleConfig, 'remove_selectors', []);
        $removeSelectors = is_array($removeSelectors) ? $removeSelectors : [];

        $bestEffort = (bool) Arr::get($config, 'best_effort', true);

        $listingResponse = $this->httpClient()->get($sourceUrl);

        if (! $listingResponse->successful()) {
            throw new InvalidArgumentException('Failed to fetch listing page');
        }

        $links = $this->extractLinks($listingResponse->body(), $sourceUrl, $linkSelector, $linkAttr, $maxLinks);

        $items = [];
        $accessedAt = now();

        foreach ($links as $link) {
            $url = $link['url'];
            $titleHint = $link['title'] ?? '';

            $articleResponse = $this->httpClient()->get($url);

            if (! $articleResponse->successful()) {
                continue;
            }

            $articleHtml = $articleResponse->body();
            $crawler = new Crawler($articleHtml, $url);

            $canonicalUrl = $this->extractCanonicalUrl($crawler, $url);
            $title = $this->extractTitle($crawler);
            $title = $title ?: ($titleHint ?: $canonicalUrl);
            $publishedAt = $this->extractPublishedAt($crawler);
            $metaDescription = $this->extractMetaDescription($crawler);

            [$bodyHtml, $cleanedText] = $this->extractBody($crawler, $contentSelector, $removeSelectors);

            if ($cleanedText === '' && $metaDescription !== '' && $bestEffort) {
                $cleanedText = $metaDescription;
            }

            if ($cleanedText === '') {
                continue;
            }

            $contentType = $this->determineContentType($cleanedText, $bestEffort);

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $title,
                'published_at' => $publishedAt,
                'summary' => $metaDescription ?: null,
                'content_type' => $contentType,
                'canonical_url' => $canonicalUrl,
                'body' => [
                    'raw_html' => $bodyHtml,
                    'cleaned_text' => $cleanedText,
                ],
                'source' => [
                    'source_type' => 'html',
                    'source_url' => $canonicalUrl,
                    'accessed_at' => $accessedAt,
                ],
                'content_hash' => sha1($cleanedText),
            ];
        }

        return $items;
    }

    /**
     * @return array<int, array{url: string, title: string}>
     */
    private function extractLinks(string $html, string $baseUrl, string $selector, string $linkAttr, int $maxLinks): array
    {
        $crawler = new Crawler($html, $baseUrl);

        $links = $crawler->filter($selector)->each(function (Crawler $node) use ($linkAttr, $baseUrl) {
            $href = $node->attr($linkAttr) ?? '';
            $resolved = $this->resolveUrl($href, $baseUrl);

            if (! $resolved) {
                return null;
            }

            return [
                'url' => $resolved,
                'title' => $this->normalizeWhitespace($node->text('')),
            ];
        });

        $links = array_values(array_filter($links, fn ($link) => $link !== null));

        $seen = [];
        $deduped = [];

        foreach ($links as $link) {
            if (isset($seen[$link['url']])) {
                continue;
            }

            $seen[$link['url']] = true;
            $deduped[] = $link;

            if ($maxLinks > 0 && count($deduped) >= $maxLinks) {
                break;
            }
        }

        return $deduped;
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (Str::startsWith($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$url;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        $base = parse_url($baseUrl);

        if (! $base || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $path = $base['path'] ?? '/';

        if (Str::startsWith($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        $directory = Str::finish($directory, '/');
        $directory = Str::start($directory, '/');

        return "{$scheme}://{$host}{$port}{$directory}{$url}";
    }

    private function extractCanonicalUrl(Crawler $crawler, string $fallback): string
    {
        $canonical = $this->firstAttr($crawler, 'link[rel="canonical"]', 'href')
            ?? $this->firstAttr($crawler, 'meta[property="og:url"]', 'content');

        if ($canonical) {
            $resolved = $this->resolveUrl($canonical, $fallback);

            if ($resolved) {
                return $resolved;
            }
        }

        return $fallback;
    }

    private function extractTitle(Crawler $crawler): string
    {
        $candidates = [
            $this->firstAttr($crawler, 'meta[property="og:title"]', 'content'),
            $this->firstAttr($crawler, 'meta[name="twitter:title"]', 'content'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        $h1 = $crawler->filter('h1');

        if ($h1->count() > 0) {
            $text = $this->normalizeWhitespace($h1->first()->text(''));

            if ($text !== '') {
                return $text;
            }
        }

        $title = $crawler->filter('title');

        if ($title->count() > 0) {
            return $this->normalizeWhitespace($title->first()->text(''));
        }

        return '';
    }

    private function extractPublishedAt(Crawler $crawler): ?Carbon
    {
        $metaSelectors = [
            'meta[property="article:published_time"]',
            'meta[name="article:published_time"]',
            'meta[name="pubdate"]',
            'meta[name="publish-date"]',
            'meta[name="date"]',
            'meta[itemprop="datePublished"]',
        ];

        foreach ($metaSelectors as $selector) {
            $value = $this->firstAttr($crawler, $selector, 'content');

            if ($value) {
                $date = $this->parseDate($value);

                if ($date) {
                    return $date;
                }
            }
        }

        $timeTag = $crawler->filter('time');

        if ($timeTag->count() > 0) {
            $attr = $timeTag->first()->attr('datetime') ?? '';
            $text = $timeTag->first()->text('');
            $candidate = $attr !== '' ? $attr : $text;

            $date = $this->parseDate($candidate);

            if ($date) {
                return $date;
            }
        }

        return null;
    }

    private function extractMetaDescription(Crawler $crawler): string
    {
        $candidates = [
            $this->firstAttr($crawler, 'meta[name="description"]', 'content'),
            $this->firstAttr($crawler, 'meta[property="og:description"]', 'content'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @param  array<int, string>  $removeSelectors
     * @return array{0: ?string, 1: string}
     */
    private function extractBody(Crawler $crawler, string $contentSelector, array $removeSelectors): array
    {
        $nodes = $crawler->filter($contentSelector);

        if ($nodes->count() === 0) {
            return [null, ''];
        }

        $htmlParts = [];

        foreach ($nodes as $node) {
            $nodeCrawler = new Crawler($node);

            foreach ($removeSelectors as $removeSelector) {
                $nodeCrawler->filter($removeSelector)->each(function (Crawler $removeNode) {
                    $domNode = $removeNode->getNode(0);

                    if ($domNode && $domNode->parentNode) {
                        $domNode->parentNode->removeChild($domNode);
                    }
                });
            }

            $html = $nodeCrawler->html();

            if ($html !== null) {
                $htmlParts[] = trim($html);
            }
        }

        if (empty($htmlParts)) {
            return [null, ''];
        }

        $rawHtml = implode("\n", $htmlParts);
        $cleaned = $this->extractCleanedText($rawHtml);

        return [$rawHtml, $cleaned];
    }

    private function determineContentType(string $cleanedText, bool $bestEffort): string
    {
        $wordCount = str_word_count($cleanedText);
        $charCount = strlen($cleanedText);

        if ($wordCount >= 80 || $charCount >= 600) {
            return 'full';
        }

        if ($bestEffort && ($wordCount >= 30 || $charCount >= 250)) {
            return 'full';
        }

        return 'snippet';
    }

    private function extractCleanedText(string $html): string
    {
        $crawler = new Crawler($html);

        $root = $crawler->filter('#contents');

        if ($root->count() === 0) {
            $root = $crawler->filter('main, article');
        }

        if ($root->count() === 0) {
            $root = $crawler->filter('body');
        }

        $parts = [];

        foreach (['h1', 'h2', 'h3', 'p', 'li'] as $selector) {
            $root->filter($selector)->each(function (Crawler $node) use (&$parts) {
                $text = $this->normalizeWhitespace($node->text(''));

                if ($text !== '') {
                    $parts[] = $text;
                }
            });
        }

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

        if ($text === '') {
            $text = $this->fallbackHtmlToText($html);
        }

        return $text;
    }

    private function fallbackHtmlToText(string $html): string
    {
        $replacements = [
            '</p>' => "\n\n",
            '</li>' => "\n",
            '<br>' => "\n",
            '<br/>' => "\n",
            '<br />' => "\n",
            '</h1>' => "\n\n",
            '</h2>' => "\n\n",
            '</h3>' => "\n\n",
            '</tr>' => "\n",
        ];

        $value = str_ireplace(array_keys($replacements), array_values($replacements), $html);
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $value = preg_replace("/\r\n?/", "\n", $value) ?? '';
        $value = preg_replace("/[ \t\f\v]+/", ' ', $value) ?? '';
        $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? '';

        return trim($value);
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->first()->attr($attr);

        return $value ? $this->normalizeWhitespace($value) : null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function httpClient()
    {
        return Http::timeout(20)
            ->retry(2, 250)
            ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0']);
    }
}
