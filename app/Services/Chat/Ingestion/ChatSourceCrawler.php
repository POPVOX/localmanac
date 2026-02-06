<?php

namespace App\Services\Chat\Ingestion;

use App\Models\ChatSource;
use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\PdfTextExtractor;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;

class ChatSourceCrawler
{
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly HtmlTextExtractor $htmlTextExtractor,
        private readonly PdfTextExtractor $pdfTextExtractor,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function crawl(ChatSource $source): array
    {
        $maxPages = (int) config('chat.crawl_max_pages', 250);
        $maxDepth = (int) config('chat.crawl_max_depth', 3);
        $allowExternal = (bool) config('chat.crawl_allow_external', false);
        $maxLinks = (int) config('chat.link_limit', 6);

        $queue = new \SplQueue;
        $queue->enqueue(['url' => $source->source_url, 'depth' => 0]);

        $visited = [];
        $pages = [];
        $rendererOverride = $source->crawl_renderer ?? null;

        while (! $queue->isEmpty() && count($pages) < $maxPages) {
            $item = $queue->dequeue();
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            $fetchStartedAt = microtime(true);
            $result = $this->fetcher->fetch($url, $rendererOverride);
            $fetchDurationMs = (int) round((microtime(true) - $fetchStartedAt) * 1000);

            if ($result === null) {
                continue;
            }

            $contentType = strtolower((string) ($result['content_type'] ?? ''));
            $body = (string) $result['body'];
            $contentText = '';
            $links = [];
            $contentLinks = [];
            $title = null;
            $canonicalUrl = null;

            if ($this->isPdfResponse($contentType, $url)) {
                try {
                    $contentText = $this->pdfTextExtractor->extract($body);
                } catch (\Throwable) {
                    $contentText = '';
                }
            } else {
                $extracted = $this->htmlTextExtractor->extract($body, $url);
                $contentText = (string) ($extracted['text'] ?? '');
                $links = $extracted['links'] ?? [];
                $contentLinks = $extracted['content_links'] ?? [];
                $title = $extracted['title'] ?? null;
                $canonicalUrl = $extracted['canonical_url'] ?? null;
            }

            $contentText = $this->limitContent($contentText);

            $pages[] = [
                'url' => $url,
                'canonical_url' => $canonicalUrl,
                'title' => $title,
                'content_type' => $this->isPdfResponse($contentType, $url) ? 'pdf' : 'html',
                'renderer' => $result['renderer'] ?? 'http',
                'status_code' => $result['status_code'] ?? null,
                'fetch_duration_ms' => $fetchDurationMs,
                'content_text' => $contentText,
                'content_length' => mb_strlen($contentText),
                'links' => $links,
                'content_links' => $contentLinks,
            ];

            if ($depth >= $maxDepth) {
                continue;
            }

            $candidates = $contentLinks !== [] ? $contentLinks : $links;

            foreach (array_slice($candidates, 0, $maxLinks * 4) as $link) {
                $resolved = $this->resolveUrl($url, $link['href'] ?? '');

                if ($resolved === null) {
                    continue;
                }

                if (! $allowExternal && ! $this->isSameHost($resolved, $source->source_url)) {
                    continue;
                }

                if ($this->isBlockedPath($resolved)) {
                    continue;
                }

                $queue->enqueue([
                    'url' => $resolved,
                    'depth' => $depth + 1,
                ]);
            }
        }

        return $pages;
    }

    private function limitContent(string $text): string
    {
        $limit = (int) config('chat.crawl_max_chars_per_page', config('chat.max_chars_per_page', 12000));

        if ($limit <= 0 || $text === '') {
            return $text;
        }

        return Str::limit($text, $limit, '');
    }

    private function resolveUrl(string $baseUrl, string $href): ?string
    {
        $href = trim($href);

        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        if (str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'tel:')) {
            return null;
        }

        try {
            $base = new Uri($baseUrl);
            $relative = new Uri($href);
            $resolved = UriResolver::resolve($base, $relative)->withFragment('');

            return (string) $resolved;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isSameHost(string $url, string $baseUrl): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($host) || ! is_string($baseHost)) {
            return false;
        }

        return $host === $baseHost;
    }

    private function isBlockedPath(string $url): bool
    {
        $blocked = [
            '/search',
            '/directory',
            '/calendar',
            '/sitemap',
            '/accessibility',
        ];

        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach ($blocked as $fragment) {
            if ($fragment !== '' && str_contains($path, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function isPdfResponse(string $contentType, string $url): bool
    {
        if (str_contains($contentType, 'pdf')) {
            return true;
        }

        return str_ends_with(mb_strtolower($url), '.pdf');
    }
}
