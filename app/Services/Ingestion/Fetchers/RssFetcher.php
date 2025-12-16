<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\Scraper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use SimpleXMLElement;

class RssFetcher
{
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
        $organizationId = $scraper->config['organization_id'] ?? null;
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
            $rawHtml = $contentEncoded ?: $description;
            $cleanedText = $this->normalizeWhitespace(strip_tags($rawHtml ?? ''));
            $summary = $this->normalizeWhitespace(strip_tags($description));
            $rawText = $rawHtml ? $this->normalizeWhitespace(strip_tags($rawHtml)) : '';
            $publishedAt = $this->parseDate($this->stringValue($item->pubDate));

            $items[] = [
                'city_id' => $scraper->city_id,
                'scraper_id' => $scraper->id,
                'title' => $title,
                'summary' => $summary ?: null,
                'published_at' => $publishedAt,
                'content_type' => $defaultContentType,
                'status' => 'published',
                'canonical_url' => $link,
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

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
