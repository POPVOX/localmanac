<?php

namespace App\Services\Ingestion;

use App\Models\Article;
use App\Models\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Symfony\Component\DomCrawler\Crawler;

class ArticlePublishedAtRepairer
{
    /**
     * @return array{
     *     scrapers: int,
     *     scanned: int,
     *     matched: int,
     *     needs_update: int,
     *     updated: int,
     *     unmatched: int,
     *     feedless: int,
     *     results: array<int, array{
     *         scraper_id: int,
     *         scraper_slug: string,
     *         scraper_name: string,
     *         feed_url: string|null,
     *         scanned: int,
     *         matched: int,
     *         needs_update: int,
     *         updated: int,
     *         unmatched: int,
     *         status: string
     *     }>
     * }
     */
    public function repair(
        ?string $city = null,
        ?string $scraperIdentifier = null,
        bool $apply = false,
        ?int $limit = null,
        ?string $feedOverride = null,
    ): array {
        $results = [];
        $totals = [
            'scrapers' => 0,
            'scanned' => 0,
            'matched' => 0,
            'needs_update' => 0,
            'updated' => 0,
            'unmatched' => 0,
            'feedless' => 0,
        ];

        $remaining = $limit;

        foreach ($this->targetScrapers($city, $scraperIdentifier) as $scraper) {
            if ($remaining !== null && $remaining <= 0) {
                break;
            }

            $feedUrl = $this->resolveFeedUrl($scraper, $feedOverride);

            if ($feedUrl === null) {
                $result = [
                    'scraper_id' => $scraper->id,
                    'scraper_slug' => $scraper->slug,
                    'scraper_name' => $scraper->name,
                    'feed_url' => null,
                    'scanned' => 0,
                    'matched' => 0,
                    'needs_update' => 0,
                    'updated' => 0,
                    'unmatched' => 0,
                    'status' => 'feed_unavailable',
                ];

                $results[] = $result;
                $totals['scrapers']++;
                $totals['feedless']++;

                continue;
            }

            $feedEntries = $this->fetchFeedEntries($feedUrl);

            if ($feedEntries === []) {
                $result = [
                    'scraper_id' => $scraper->id,
                    'scraper_slug' => $scraper->slug,
                    'scraper_name' => $scraper->name,
                    'feed_url' => $feedUrl,
                    'scanned' => 0,
                    'matched' => 0,
                    'needs_update' => 0,
                    'updated' => 0,
                    'unmatched' => 0,
                    'status' => 'feed_empty',
                ];

                $results[] = $result;
                $totals['scrapers']++;

                continue;
            }

            $articles = Article::query()
                ->where('scraper_id', $scraper->id)
                ->with('sources')
                ->orderBy('id')
                ->when($remaining !== null, fn ($query) => $query->limit($remaining))
                ->get();

            $scanned = 0;
            $matched = 0;
            $needsUpdate = 0;
            $updated = 0;

            foreach ($articles as $article) {
                $scanned++;

                $resolvedPublishedAt = $this->resolvePublishedAtFromFeed($article, $feedEntries);

                if (! $resolvedPublishedAt instanceof Carbon) {
                    continue;
                }

                $matched++;

                if ($this->publishedAtMatches($article->published_at, $resolvedPublishedAt)) {
                    continue;
                }

                $needsUpdate++;

                if (! $apply) {
                    continue;
                }

                $article->forceFill([
                    'published_at' => $resolvedPublishedAt,
                ])->save();

                $updated++;
            }

            $unmatched = max(0, $scanned - $matched);

            $results[] = [
                'scraper_id' => $scraper->id,
                'scraper_slug' => $scraper->slug,
                'scraper_name' => $scraper->name,
                'feed_url' => $feedUrl,
                'scanned' => $scanned,
                'matched' => $matched,
                'needs_update' => $needsUpdate,
                'updated' => $updated,
                'unmatched' => $unmatched,
                'status' => 'ok',
            ];

            $totals['scrapers']++;
            $totals['scanned'] += $scanned;
            $totals['matched'] += $matched;
            $totals['needs_update'] += $needsUpdate;
            $totals['updated'] += $updated;
            $totals['unmatched'] += $unmatched;

            if ($remaining !== null) {
                $remaining -= $scanned;
            }
        }

        return [
            ...$totals,
            'results' => $results,
        ];
    }

    /**
     * @return Collection<int, Scraper>
     */
    private function targetScrapers(?string $city, ?string $scraperIdentifier): Collection
    {
        return Scraper::query()
            ->with('city')
            ->where('type', 'html')
            ->orderBy('id')
            ->get()
            ->filter(function (Scraper $scraper) use ($city, $scraperIdentifier): bool {
                if (($scraper->config['profile'] ?? null) !== 'generic_listing') {
                    return false;
                }

                if ($city !== null && $city !== '') {
                    $cityMatches = ctype_digit($city)
                        ? $scraper->city_id === (int) $city
                        : $scraper->city?->slug === $city;

                    if (! $cityMatches) {
                        return false;
                    }
                }

                if ($scraperIdentifier !== null && $scraperIdentifier !== '') {
                    return ctype_digit($scraperIdentifier)
                        ? $scraper->id === (int) $scraperIdentifier
                        : $scraper->slug === $scraperIdentifier;
                }

                return true;
            })
            ->values();
    }

    private function resolveFeedUrl(Scraper $scraper, ?string $feedOverride): ?string
    {
        if (is_string($feedOverride) && trim($feedOverride) !== '') {
            return trim($feedOverride);
        }

        $configured = $scraper->config['repair']['feed_url'] ?? $scraper->config['feed_url'] ?? null;

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        $sourceUrl = trim((string) $scraper->source_url);

        if ($sourceUrl === '') {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 250)
                ->get($sourceUrl);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        try {
            $crawler = new Crawler($response->body(), $sourceUrl);
        } catch (\Throwable) {
            return null;
        }

        $linkNodes = $crawler->filter('link[href]');

        foreach ($linkNodes as $node) {
            $rel = mb_strtolower(trim((string) $node->getAttribute('rel')));
            $type = mb_strtolower(trim((string) $node->getAttribute('type')));
            $href = trim((string) $node->getAttribute('href'));

            if ($href === '' || ! str_contains($rel, 'alternate')) {
                continue;
            }

            if (
                ! str_contains($type, 'rss')
                && ! str_contains($type, 'atom')
                && ! str_contains($type, 'xml')
            ) {
                continue;
            }

            return $this->resolveUrl($href, $sourceUrl);
        }

        return null;
    }

    /**
     * @return array<string, Carbon>
     */
    private function fetchFeedEntries(string $feedUrl): array
    {
        try {
            $response = Http::timeout(15)
                ->retry(2, 250)
                ->get($feedUrl);
        } catch (\Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $entries = [];

        if (isset($xml->channel->item)) {
            foreach ($xml->channel->item as $item) {
                $link = trim((string) $item->link);
                $publishedAt = $this->parseFeedDate(
                    trim((string) $item->pubDate) !== ''
                        ? trim((string) $item->pubDate)
                        : trim((string) $item->children('http://purl.org/dc/elements/1.1/')->date)
                );

                if ($link === '' || ! $publishedAt instanceof Carbon) {
                    continue;
                }

                foreach ($this->urlKeys($link) as $key) {
                    if (! isset($entries[$key])) {
                        $entries[$key] = $publishedAt;
                    }
                }
            }
        }

        if ($xml->getName() === 'feed' || isset($xml->entry)) {
            foreach ($xml->entry as $entry) {
                $href = '';

                foreach ($entry->link as $linkNode) {
                    $candidate = trim((string) ($linkNode['href'] ?? ''));

                    if ($candidate !== '') {
                        $href = $candidate;
                        break;
                    }
                }

                $publishedAt = $this->parseFeedDate(
                    trim((string) $entry->published) !== ''
                        ? trim((string) $entry->published)
                        : trim((string) $entry->updated)
                );

                if ($href === '' || ! $publishedAt instanceof Carbon) {
                    continue;
                }

                foreach ($this->urlKeys($href) as $key) {
                    if (! isset($entries[$key])) {
                        $entries[$key] = $publishedAt;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, Carbon>  $feedEntries
     */
    private function resolvePublishedAtFromFeed(Article $article, array $feedEntries): ?Carbon
    {
        $candidateUrls = collect([
            $article->canonical_url,
            ...$article->sources->pluck('source_url')->all(),
        ])
            ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        foreach ($candidateUrls as $candidateUrl) {
            foreach ($this->urlKeys($candidateUrl) as $key) {
                if (isset($feedEntries[$key])) {
                    return $feedEntries[$key]->copy();
                }
            }
        }

        return null;
    }

    private function publishedAtMatches(mixed $current, Carbon $resolvedPublishedAt): bool
    {
        if (! $current instanceof Carbon) {
            return false;
        }

        return $current->copy()->utc()->equalTo($resolvedPublishedAt->copy()->utc());
    }

    private function parseFeedDate(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function urlKeys(string $url): array
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['host'])) {
            return [];
        }

        $host = preg_replace('/^www\./', '', mb_strtolower((string) $parts['host'])) ?? mb_strtolower((string) $parts['host']);
        $path = '/'.trim(rawurldecode((string) ($parts['path'] ?? '/')), '/');
        $path = $path === '//' ? '/' : $path;

        parse_str((string) ($parts['query'] ?? ''), $query);

        $query = collect($query)
            ->reject(fn ($value, string $key): bool => Str::startsWith(mb_strtolower($key), 'utm_'))
            ->except(['fbclid', 'gclid'])
            ->sortKeys()
            ->all();

        $pathKey = $host.$path;
        $keys = [$pathKey];

        if ($query !== []) {
            $keys[] = $pathKey.'?'.http_build_query($query);
        }

        return array_values(array_unique($keys));
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

        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }

        $scheme = (string) $base['scheme'];
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';

        if (Str::startsWith($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        $directory = Str::finish($directory, '/');
        $directory = Str::start($directory, '/');

        return "{$scheme}://{$host}{$port}{$directory}{$url}";
    }
}
