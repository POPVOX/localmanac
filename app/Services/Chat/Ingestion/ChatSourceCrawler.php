<?php

namespace App\Services\Chat\Ingestion;

use App\Models\ChatSource;
use App\Services\Chat\ChatSourceGuard;
use App\Services\Chat\HtmlTextExtractor;
use App\Services\Chat\PdfTextExtractor;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChatSourceCrawler
{
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly HtmlTextExtractor $htmlTextExtractor,
        private readonly PdfTextExtractor $pdfTextExtractor,
        private readonly ChatSourceGuard $chatSourceGuard,
        private readonly NavigationPageClassifier $navigationPageClassifier,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function crawl(ChatSource $source): array
    {
        $maxPages = max(1, (int) config('chat.crawl_max_pages', 250));
        $maxDepth = $source->link_follow_mode === 'none' ? 0 : (int) config('chat.crawl_max_depth', 3);
        $allowExternal = (bool) config('chat.crawl_allow_external', false);
        $maxLinks = max(0, (int) ($source->link_limit ?? config('chat.link_limit', 6)));

        $queue = new \SplQueue;
        $queue->enqueue(['url' => $source->source_url, 'depth' => 0]);

        $visited = [];
        $queued = [$source->source_url => true];
        $pages = [];
        $rendererOverride = $source->crawl_renderer ?? null;

        while (! $queue->isEmpty() && count($visited) < $maxPages) {
            $item = $queue->dequeue();
            $url = $item['url'];
            $depth = $item['depth'];

            if (isset($visited[$url])) {
                continue;
            }

            $visited[$url] = true;

            $fetchStartedAt = microtime(true);
            $result = $this->fetcher->fetch($url, $rendererOverride, [], true);
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

            if ($this->chatSourceGuard->isBlockedPage($url, $canonicalUrl, $title, $contentText)) {
                continue;
            }

            $isNavigationPage = $this->navigationPageClassifier->isNavigationPage($contentText);

            if ($isNavigationPage) {
                Log::debug('Skipping navigation page during crawl', ['url' => $url, 'title' => $title]);
            }

            $contentText = $this->limitContent($contentText);

            if (! $isNavigationPage) {
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
            }

            if ($depth >= $maxDepth || $maxLinks === 0) {
                continue;
            }

            $candidates = $contentLinks;
            $followed = 0;

            foreach ($candidates as $link) {
                $resolved = $this->resolveUrl($url, $link['href'] ?? '');

                if ($resolved === null || isset($queued[$resolved])) {
                    continue;
                }

                if (! $allowExternal && ! $this->isSameHost($resolved, $source->source_url)) {
                    continue;
                }

                if ($this->isBlockedPath($resolved) || $this->chatSourceGuard->isBlockedUrl($resolved)) {
                    continue;
                }

                $queue->enqueue([
                    'url' => $resolved,
                    'depth' => $depth + 1,
                ]);
                $queued[$resolved] = true;

                if (++$followed >= $maxLinks) {
                    break;
                }
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

            if (! in_array(strtolower($resolved->getScheme()), ['http', 'https'], true)) {
                return null;
            }

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

        return preg_replace('/^www\./', '', strtolower($host)) === preg_replace('/^www\./', '', strtolower($baseHost));
    }

    private function isBlockedPath(string $url): bool
    {
        $blocked = [
            '/cdn-cgi/',
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

        return str_ends_with(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf');
    }
}
