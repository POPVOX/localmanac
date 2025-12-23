<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class WichitaArchivePdfListFetcher
{
    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function fetch(Scraper $scraper): array
    {
        if ($scraper->type !== 'html') {
            throw new InvalidArgumentException('Scraper type must be html');
        }

        $config = $scraper->config ?? [];

        if (Arr::get($config, 'profile') !== 'wichita_archive_pdf_list') {
            throw new InvalidArgumentException('Scraper profile must be wichita_archive_pdf_list');
        }

        $sourceUrl = $scraper->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('Scraper source_url must exist');
        }

        $listConfig = Arr::get($config, 'list');

        if (! is_array($listConfig)) {
            throw new InvalidArgumentException('Scraper list config must exist');
        }

        $hrefContains = Arr::get($listConfig, 'href_contains');

        if (! is_string($hrefContains) || $hrefContains === '') {
            throw new InvalidArgumentException('Scraper list href_contains must exist');
        }

        $maxLinks = (int) Arr::get($listConfig, 'max_links', 50);
        $organizationId = $scraper->organization_id ?? Arr::get($config, 'organization_id');

        $response = $this->httpClient()->get($sourceUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch listing page');
        }

        $crawler = new Crawler($response->body(), $sourceUrl);

        $items = [];
        $seen = [];
        $stats = [
            'considered' => 0,
            'skipped_empty_title' => 0,
            'skipped_duplicate' => 0,
            'skipped_unmatched_href' => 0,
            'skipped_invalid_href' => 0,
            'skipped_max_links' => 0,
        ];

        foreach ($crawler->filter('a') as $node) {
            $anchor = new Crawler($node, $sourceUrl);
            $href = $anchor->attr('href') ?? '';

            if (! Str::contains($href, $hrefContains)) {
                $stats['skipped_unmatched_href']++;

                continue;
            }

            $resolved = $this->resolveUrl($href, $sourceUrl);

            if (! $resolved) {
                $stats['skipped_invalid_href']++;

                continue;
            }

            $stats['considered']++;
            $title = $this->normalizeWhitespace($anchor->text(''));

            if ($title === '' || mb_strlen($title) < 3) {
                $stats['skipped_empty_title']++;

                continue;
            }

            if (isset($seen[$resolved])) {
                $stats['skipped_duplicate']++;

                continue;
            }

            if ($maxLinks > 0 && count($items) >= $maxLinks) {
                $stats['skipped_max_links']++;

                continue;
            }

            $seen[$resolved] = true;

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $title,
                'published_at' => null,
                'content_type' => 'pdf',
                'canonical_url' => $resolved,
                'summary' => null,
                'meta' => [
                    'source' => 'archive_center',
                ],
                'source' => [
                    'source_type' => 'pdf',
                    'source_url' => $resolved,
                    'source_uid' => $this->extractArchiveId($resolved),
                    'organization_id' => $organizationId,
                    'accessed_at' => now(),
                ],
            ];
        }

        return [
            'items' => $items,
            'meta' => [
                'href_contains' => $hrefContains,
                'skipped' => $stats,
                'profile' => 'wichita_archive_pdf_list',
            ],
        ];
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

    private function extractArchiveId(string $url): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! $query) {
            return null;
        }

        parse_str($query, $params);

        $adid = $params['ADID'] ?? null;

        return is_string($adid) && $adid !== '' ? $adid : null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function httpClient()
    {
        return Http::timeout(20)
            ->retry(2, 250)
            ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0']);
    }
}
