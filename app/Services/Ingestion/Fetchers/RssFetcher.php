<?php

namespace App\Services\Ingestion\Fetchers;

use App\Enums\ArticlePublishedPrecision;
use App\Models\Scraper;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SimpleXMLElement;

class RssFetcher
{
    public function __construct(
        private readonly ?RssCanonicalBodyHydrator $canonicalBodyHydrator = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(Scraper $scraper): array
    {
        $type = $scraper->type;

        if ($type !== 'rss') {
            throw new InvalidArgumentException('Scraper type must be rss');
        }

        $feedUrl = $scraper->config['feed_url'] ?? $scraper->source_url;

        if (! $feedUrl) {
            throw new InvalidArgumentException('Missing RSS feed URL');
        }

        $response = Http::timeout(15)
            ->retry(2, 250)
            ->get($feedUrl);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch RSS feed');
        }

        $xml = simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA);

        if (! $xml instanceof SimpleXMLElement || ! isset($xml->channel->item)) {
            return [];
        }

        $items = [];
        $lang = $scraper->config['lang'] ?? 'en';
        $defaultContentType = $scraper->config['default_content_type'] ?? 'news';
        $organizationId = $scraper->organization_id ?? ($scraper->config['organization_id'] ?? null);
        $maxItems = (int) ($scraper->config['max_items'] ?? 50);
        $accessedAt = now();

        foreach ($xml->channel->item as $item) {
            $title = $this->stringValue($item->title);
            $link = $this->stringValue($item->link);

            if (! $title || ! $link) {
                continue;
            }

            $description = $this->stringValue($item->description);
            $contentEncoded = $this->contentEncoded($item);
            $summary = $this->normalizeWhitespace(strip_tags($description));
            $rawHtml = $contentEncoded ?: $description;
            $rawText = $rawHtml ? $this->normalizeWhitespace(strip_tags($rawHtml)) : '';
            $cleanedText = $rawText;
            $canonicalUrl = $link;
            $hydratedBody = $this->hydratedBody(
                cleanedText: $cleanedText,
                summary: $summary,
                contentEncoded: $contentEncoded,
                link: $link,
            );

            if ($hydratedBody !== null) {
                $rawHtml = $hydratedBody['raw_html'];
                $rawText = $hydratedBody['raw_text'];
                $cleanedText = $hydratedBody['cleaned_text'];
                $canonicalUrl = $hydratedBody['canonical_url'] ?? $canonicalUrl;
            }

            $publishedAtData = $this->parseDate($this->stringValue($item->pubDate));

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $title,
                'summary' => $summary ?: null,
                'published_at' => $publishedAtData['published_at'],
                'published_precision' => $publishedAtData['published_precision']?->value,
                'content_type' => $defaultContentType,
                'status' => 'published',
                'canonical_url' => $canonicalUrl,
                'content_hash' => $cleanedText ? hash('sha256', $cleanedText) : null,
                'body' => [
                    'raw_html' => $rawHtml ?: null,
                    'raw_text' => $rawText !== '' ? $rawText : null,
                    'cleaned_text' => $cleanedText ?: null,
                    'lang' => $lang,
                ],
                'source' => [
                    'source_url' => $link,
                    'source_type' => 'rss',
                    'source_uid' => $this->stringValue($item->guid),
                    'accessed_at' => $accessedAt,
                    'organization_id' => $organizationId,
                ],
            ];

            if (count($items) >= $maxItems) {
                break;
            }
        }

        return $items;
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function contentEncoded(SimpleXMLElement $item): ?string
    {
        $content = $item->children('http://purl.org/rss/1.0/modules/content/');

        $encoded = $this->stringValue($content->encoded ?? '');

        return $encoded !== '' ? $encoded : null;
    }

    /**
     * @return array{
     *     canonical_url: string|null,
     *     raw_html: string,
     *     raw_text: string,
     *     cleaned_text: string,
     *     title: string|null,
     *     renderer: string|null
     * }|null
     */
    private function hydratedBody(
        ?string $cleanedText,
        ?string $summary,
        ?string $contentEncoded,
        string $link,
    ): ?array {
        if ($this->canonicalBodyHydrator === null) {
            return null;
        }

        if (! $this->canonicalBodyHydrator->shouldHydrate($cleanedText, $summary, $contentEncoded, $link)) {
            return null;
        }

        return $this->canonicalBodyHydrator->hydrate($link);
    }

    /**
     * @return array{published_at: ?Carbon, published_precision: ?ArticlePublishedPrecision}
     */
    private function parseDate(?string $value): array
    {
        if (! $value) {
            return [
                'published_at' => null,
                'published_precision' => null,
            ];
        }

        try {
            return [
                'published_at' => Carbon::parse($value),
                'published_precision' => $this->valueContainsExplicitTime($value)
                    ? ArticlePublishedPrecision::DateTime
                    : ArticlePublishedPrecision::Date,
            ];
        } catch (\Throwable) {
            return [
                'published_at' => null,
                'published_precision' => null,
            ];
        }
    }

    private function valueContainsExplicitTime(string $value): bool
    {
        return preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $value) === 1;
    }
}
