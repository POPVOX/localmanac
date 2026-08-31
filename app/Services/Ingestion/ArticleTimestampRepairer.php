<?php

namespace App\Services\Ingestion;

use App\Enums\ArticlePublishedPrecision;
use App\Models\Article;
use App\Services\Chat\Ingestion\PageFetcher;
use App\Services\Chat\PdfTextExtractor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\DomCrawler\Crawler;

class ArticleTimestampRepairer
{
    private const DOCUMENTERS_DATE_PATTERN = '/Date:\s*((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i';

    /**
     * @var list<string>
     */
    private const LEGAL_NOTICE_DATE_PATTERNS = [
        '/Published\s+on\s+the\s+City\'?s\s+Website\s+on\s+(?:[A-Za-z]+,\s+)?((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i',
        '/Published\s+Wichita\.gov\s+website\s+on\s+(\d{1,2}\/\d{1,2}\/\d{4})/i',
        '/Published\s+at\s+Wichita\.gov\/LegalNotices\s+on\s+((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i',
        '/published\s+on\s+such\s+website\s+beginning\s+on\s+the\s+([0-9A-Za-z]{1,4})\s*(?:st|nd|rd|th)?\s+day\s+of\s+([A-Za-z.,]+\s+[0-9A-Za-z]{4})/i',
        '/Dated\s+at\s+Wichita,\s+Kansas,?\s+((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i',
        '/\bDATED:\s*((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i',
        '/Signed:\s*.*?\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|Mareh)\.?\s+\d{1,2},\s+\d{4})/is',
        '/comments\s+received\s+on\s+or\s+before\s+((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?|Mareh)\.?\s+\d{1,2},\s+\d{4})/i',
        '/Published\s+(?:on|in)\s+.*?\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})/i',
    ];

    public function __construct(
        private readonly PageFetcher $pageFetcher,
        private readonly PdfTextExtractor $pdfTextExtractor,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     resolved: int,
     *     needs_update: int,
     *     updated: int,
     *     unresolved: int,
     *     unresolved_samples: list<array{
     *         article_id: int,
     *         scraper_name: string,
     *         scraper_slug: string,
     *         title: string,
     *         canonical_url: string|null,
     *         snippet: string|null
     *     }>,
     *     by_scraper: list<array{
     *         scraper_id: int|null,
     *         scraper_name: string,
     *         scraper_slug: string,
     *         scanned: int,
     *         resolved: int,
     *         needs_update: int,
     *         updated: int,
     *         unresolved: int
     *     }>
     * }
     */
    public function repair(
        ?string $city = null,
        ?string $scraperIdentifier = null,
        bool $apply = false,
        ?int $limit = null,
        ?Carbon $before = null,
        int $sampleUnresolved = 5,
    ): array {
        $articles = $this->targetArticles($city, $scraperIdentifier, $limit, $before);

        $resolved = 0;
        $needsUpdate = 0;
        $updated = 0;
        $unresolved = 0;
        $byScraper = [];
        $unresolvedSamples = [];

        foreach ($articles as $article) {
            $scraperKey = (string) ($article->scraper_id ?? 'unknown');
            $byScraper[$scraperKey] ??= [
                'scraper_id' => $article->scraper_id,
                'scraper_name' => $article->scraper?->name ?? 'Unknown scraper',
                'scraper_slug' => $article->scraper?->slug ?? 'unknown',
                'scanned' => 0,
                'resolved' => 0,
                'needs_update' => 0,
                'updated' => 0,
                'unresolved' => 0,
            ];
            $byScraper[$scraperKey]['scanned']++;

            $repair = $this->resolveRepair($article);

            if ($repair === null) {
                $unresolved++;
                $byScraper[$scraperKey]['unresolved']++;

                if (count($unresolvedSamples) < $sampleUnresolved) {
                    $unresolvedSamples[] = [
                        'article_id' => $article->id,
                        'scraper_name' => $article->scraper?->name ?? 'Unknown scraper',
                        'scraper_slug' => $article->scraper?->slug ?? 'unknown',
                        'title' => $article->title,
                        'canonical_url' => $article->canonical_url,
                        'snippet' => $this->unresolvedSnippet($article),
                    ];
                }

                continue;
            }

            $resolved++;
            $byScraper[$scraperKey]['resolved']++;

            if ($this->matches($article, $repair['published_at'], $repair['published_precision'])) {
                continue;
            }

            $needsUpdate++;
            $byScraper[$scraperKey]['needs_update']++;

            if (! $apply) {
                continue;
            }

            $article->forceFill([
                'published_at' => $repair['published_at'],
                'published_precision' => $repair['published_precision']?->value,
            ])->save();

            $updated++;
            $byScraper[$scraperKey]['updated']++;
        }

        $scraperSummaries = collect($byScraper)
            ->sortByDesc(fn (array $summary): array => [
                $summary['unresolved'],
                $summary['needs_update'],
                $summary['scanned'],
                $summary['scraper_name'],
            ])
            ->values()
            ->all();

        return [
            'scanned' => $articles->count(),
            'resolved' => $resolved,
            'needs_update' => $needsUpdate,
            'updated' => $updated,
            'unresolved' => $unresolved,
            'unresolved_samples' => $unresolvedSamples,
            'by_scraper' => $scraperSummaries,
        ];
    }

    /**
     * @return Collection<int, Article>
     */
    private function targetArticles(?string $city, ?string $scraperIdentifier, ?int $limit, ?Carbon $before): Collection
    {
        return Article::query()
            ->with(['scraper.city', 'city', 'sources', 'body'])
            ->whereNotNull('scraper_id')
            ->when($city !== null && $city !== '', function ($query) use ($city): void {
                if (ctype_digit($city)) {
                    $query->where('city_id', (int) $city);

                    return;
                }

                $query->whereHas('city', fn ($builder) => $builder->where('slug', $city));
            })
            ->when($scraperIdentifier !== null && $scraperIdentifier !== '', function ($query) use ($scraperIdentifier): void {
                $query->whereHas('scraper', function ($builder) use ($scraperIdentifier): void {
                    if (ctype_digit($scraperIdentifier)) {
                        $builder->where('id', (int) $scraperIdentifier);

                        return;
                    }

                    $builder->where('slug', $scraperIdentifier);
                });
            })
            ->when($before !== null, fn ($query) => $query->where('created_at', '<=', $before))
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    /**
     * @return array{published_at: ?Carbon, published_precision: ?ArticlePublishedPrecision}|null
     */
    private function resolveRepair(Article $article): ?array
    {
        $scraper = $article->scraper;

        if ($scraper === null) {
            return null;
        }

        $timezone = $scraper->city?->timezone ?? $article->city?->timezone ?? config('app.timezone', 'UTC');
        $profile = is_array($scraper->config) ? ($scraper->config['profile'] ?? null) : null;

        if ($scraper->type === 'rss') {
            if (! $article->published_at instanceof Carbon) {
                return null;
            }

            return [
                'published_at' => $article->published_at->copy()->utc(),
                'published_precision' => ArticlePublishedPrecision::DateTime,
            ];
        }

        if (in_array($profile, ['documenters', 'wichitadocumenters'], true)) {
            $publishedAt = $this->resolveDocumentersPublishedAt($article, $timezone);

            if (! $publishedAt instanceof Carbon) {
                return null;
            }

            return [
                'published_at' => $publishedAt,
                'published_precision' => ArticlePublishedPrecision::Date,
            ];
        }

        if (in_array($profile, ['civicplus_archive_pdf_list', 'wichita_archive_pdf_list'], true)) {
            $publishedAt = $this->resolveArchivePdfPublishedAt($article, $timezone);

            if (! $publishedAt instanceof Carbon) {
                return null;
            }

            return [
                'published_at' => $publishedAt,
                'published_precision' => ArticlePublishedPrecision::Date,
            ];
        }

        if ($profile === 'generic_listing') {
            if (is_string($article->body?->raw_html) && trim($article->body->raw_html) !== '') {
                $resolved = $this->extractGenericListingPublishedAt($article->body->raw_html, $article->canonical_url ?? '', $timezone);

                if ($resolved !== null) {
                    return $resolved;
                }
            }

            $sourceUrl = $this->articleSourceUrl($article);

            if ($sourceUrl === null) {
                return null;
            }

            $html = $this->fetchPageHtml($sourceUrl);

            if ($html === null) {
                return null;
            }

            return $this->extractGenericListingPublishedAt($html, $sourceUrl, $timezone);
        }

        return null;
    }

    private function articleSourceUrl(Article $article): ?string
    {
        $candidates = collect([
            $article->canonical_url,
            ...$article->sources->pluck('source_url')->all(),
        ])->filter(fn ($value): bool => is_string($value) && trim($value) !== '');

        $url = $candidates->first();

        return is_string($url) ? trim($url) : null;
    }

    private function fetchPageHtml(string $url): ?string
    {
        foreach (['auto', 'playwright'] as $renderer) {
            $result = $this->pageFetcher->fetch($url, $renderer);
            $html = $result['body'] ?? null;

            if (is_string($html) && ! $this->looksLikeBotChallengePage($html)) {
                return $html;
            }
        }

        return null;
    }

    private function looksLikeBotChallengePage(string $html): bool
    {
        $lower = mb_strtolower($html);

        foreach ([
            'px-captcha',
            'access to this page has been denied',
            'before we continue',
            'checking your browser',
            'verify you are human',
            'ppconfig',
        ] as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{published_at: Carbon, published_precision: ArticlePublishedPrecision}|null
     */
    private function extractGenericListingPublishedAt(string $html, string $url, string $timezone): ?array
    {
        $crawler = new Crawler($html, $url);
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

            if ($value === null) {
                continue;
            }

            $date = $this->parseGenericDate($value, $timezone);

            if ($date !== null) {
                return $date;
            }
        }

        foreach ([
            '.sno-story-byline .time-wrapper',
            '.byline-inner-container .time-wrapper',
            '.sno-story-byline',
            '.entry-date',
            '.posted-on',
            '.post-date',
        ] as $selector) {
            $value = $this->firstText($crawler, $selector);

            if ($value === null) {
                continue;
            }

            $date = $this->extractGenericDateFromText($value, $timezone);

            if ($date !== null) {
                return $date;
            }
        }

        foreach ($crawler->filter('time') as $node) {
            $nodeCrawler = new Crawler($node);
            $candidate = $nodeCrawler->attr('datetime') ?? $nodeCrawler->text('');
            $date = $this->parseGenericDate($candidate, $timezone)
                ?? $this->extractGenericDateFromText($candidate, $timezone);

            if ($date !== null) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return array{published_at: Carbon, published_precision: ArticlePublishedPrecision}|null
     */
    private function parseGenericDate(string $value, string $timezone): ?array
    {
        try {
            $date = Carbon::parse($value, $timezone);

            if (! $this->containsExplicitTime($value)) {
                return [
                    'published_at' => $date->startOfDay(),
                    'published_precision' => ArticlePublishedPrecision::Date,
                ];
            }

            return [
                'published_at' => $date,
                'published_precision' => ArticlePublishedPrecision::DateTime,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{published_at: Carbon, published_precision: ArticlePublishedPrecision}|null
     */
    private function extractGenericDateFromText(string $value, string $timezone): ?array
    {
        if (preg_match('/\b(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4}(?:\s+\d{1,2}:\d{2}(?:\s?[AP]M)?)?/i', $value, $matches) !== 1) {
            return null;
        }

        $candidate = trim((string) ($matches[0] ?? ''));

        return $candidate === '' ? null : $this->parseGenericDate($candidate, $timezone);
    }

    private function resolveArchivePdfPublishedAt(Article $article, string $timezone): ?Carbon
    {
        $titleDate = $this->extractArchivePdfTitleDate($article, $timezone);

        if ($titleDate instanceof Carbon && $this->isPlausiblePublishedAt($article, $titleDate, $timezone)) {
            return $titleDate;
        }

        $textCandidates = [
            $article->body?->cleaned_text,
            $article->body?->raw_text,
            $article->summary,
            $article->title,
        ];

        foreach ($textCandidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $resolved = $this->extractArchivePdfPublishedAt($candidate, $timezone);

            if ($resolved instanceof Carbon && $this->isPlausiblePublishedAt($article, $resolved, $timezone)) {
                return $resolved;
            }
        }

        $sourceUrl = $this->articleSourceUrl($article);

        if ($sourceUrl === null) {
            return null;
        }

        $payload = $this->fetchArchiveDocumentPayload($sourceUrl, $timezone);

        if (is_string($payload['text']) && $payload['text'] !== '') {
            $resolved = $this->extractArchivePdfPublishedAt($payload['text'], $timezone);

            if ($resolved instanceof Carbon && $this->isPlausiblePublishedAt($article, $resolved, $timezone)) {
                return $resolved;
            }
        }

        $metadataDate = $payload['metadata_date'];

        return $metadataDate instanceof Carbon && $this->isPlausiblePublishedAt($article, $metadataDate, $timezone)
            ? $metadataDate
            : null;
    }

    private function extractArchivePdfTitleDate(Article $article, string $timezone): ?Carbon
    {
        if (! is_string($article->title) || trim($article->title) === '') {
            return null;
        }

        if (preg_match('/\b(\d{1,2})[.\-](\d{1,2})[.\-](\d{4})\b/', $article->title, $matches) !== 1) {
            return null;
        }

        $month = (int) ($matches[1] ?? 0);
        $day = (int) ($matches[2] ?? 0);
        $year = (int) ($matches[3] ?? 0);

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31 || $year < 2000 || $year > 2100) {
            return null;
        }

        try {
            return Carbon::create($year, $month, $day, 0, 0, 0, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{text: string|null, metadata_date: Carbon|null}
     */
    private function fetchArchiveDocumentPayload(string $url, string $timezone): array
    {
        try {
            $response = Http::timeout(45)
                ->retry(2, 500)
                ->withHeaders(['User-Agent' => 'LocalmanacBot/1.0'])
                ->get($url);
        } catch (\Throwable) {
            return ['text' => null, 'metadata_date' => null];
        }

        if (! $response->successful()) {
            return ['text' => null, 'metadata_date' => null];
        }

        $contentType = mb_strtolower((string) $response->header('Content-Type'));
        $body = (string) $response->body();

        if ($body === '') {
            return ['text' => null, 'metadata_date' => null];
        }

        if (str_contains($contentType, 'application/pdf') || Str::startsWith($body, '%PDF')) {
            $metadataDate = $this->extractArchivePdfMetadataDate($body, $timezone);

            try {
                return [
                    'text' => $this->pdfTextExtractor->extract($body),
                    'metadata_date' => $metadataDate,
                ];
            } catch (\Throwable) {
                return [
                    'text' => null,
                    'metadata_date' => $metadataDate,
                ];
            }
        }

        $text = trim(strip_tags($body));

        return [
            'text' => $text !== '' ? $text : null,
            'metadata_date' => null,
        ];
    }

    private function extractArchivePdfMetadataDate(string $binary, string $timezone): ?Carbon
    {
        $matches = null;

        foreach ([
            '/\/CreationDate\s*\(D:(\d{4})(\d{2})(\d{2})\d{6}(?:[+\-Z].*?)?\)/',
            '/<xmp:CreateDate>(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(?:[+\-]\d{2}:\d{2}|Z)<\/xmp:CreateDate>/i',
        ] as $pattern) {
            if (preg_match($pattern, $binary, $candidateMatches) === 1) {
                $matches = $candidateMatches;

                break;
            }
        }

        if (! is_array($matches)) {
            return null;
        }

        try {
            return Carbon::create(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3],
                0,
                0,
                0,
                $timezone,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    private function extractArchivePdfPublishedAt(string $content, string $timezone): ?Carbon
    {
        foreach (self::LEGAL_NOTICE_DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $content, $matches) !== 1) {
                continue;
            }

            $candidate = $this->normalizeArchivePdfDateCandidate($matches);

            if ($candidate === '') {
                continue;
            }

            try {
                return Carbon::parse(str_replace('.', '', $candidate), $timezone)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->extractArchivePdfLeadingDate($content, $timezone);
    }

    private function extractArchivePdfLeadingDate(string $content, string $timezone): ?Carbon
    {
        $leadingText = $this->normalizeArchivePdfMonthYear($this->archiveLeadingTextWindow($content));
        $leadingText = $this->normalizeArchivePdfYearToken($leadingText);

        foreach ([
            '/\b((?:Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:t(?:ember)?)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\.?\s+\d{1,2},\s+\d{4})\b/i',
            '/\b(\d{1,2}\/\d{1,2}\/\d{4})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $leadingText, $matches) !== 1) {
                continue;
            }

            $candidate = $this->normalizeArchivePdfDateCandidate($matches);

            if ($candidate === '') {
                continue;
            }

            try {
                return Carbon::parse($candidate, $timezone)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function archiveLeadingTextWindow(string $content): string
    {
        $normalized = preg_replace("/\r\n?/", "\n", $content) ?? $content;
        $lines = array_values(array_filter(array_map(
            fn (string $line): string => $this->normalizeWhitespace($line),
            preg_split("/\n+/", $normalized) ?: []
        ), fn (string $line): bool => $line !== ''));

        $window = implode(' ', array_slice($lines, 0, 12));

        return mb_substr($window, 0, 600);
    }

    private function isPlausiblePublishedAt(Article $article, Carbon $publishedAt, string $timezone): bool
    {
        $candidate = $publishedAt->copy()->setTimezone($timezone)->startOfDay();
        $now = Carbon::now($timezone)->endOfDay();

        if ($candidate->greaterThan($now)) {
            return false;
        }

        if (! $article->created_at instanceof Carbon) {
            return true;
        }

        return $candidate->lessThanOrEqualTo(
            $article->created_at->copy()->setTimezone($timezone)->endOfDay()
        );
    }

    /**
     * @param  array<int|string, string>  $matches
     */
    private function normalizeArchivePdfDateCandidate(array $matches): string
    {
        if (isset($matches[2]) && isset($matches[1]) && preg_match('/^[0-9A-Za-z]{1,4}$/', trim((string) $matches[1])) === 1) {
            $day = $this->normalizeArchivePdfDayToken((string) $matches[1]);
            $monthYear = $this->normalizeArchivePdfMonthYear((string) $matches[2]);

            if ($day !== null && $monthYear !== '') {
                $normalized = $monthYear;

                if (preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $normalized, $parts) === 1) {
                    $normalized = sprintf('%s %d, %s', $parts[1], $day, $parts[2]);
                } else {
                    $normalized = sprintf('%d %s', $day, $monthYear);
                }
            } else {
                $normalized = trim((string) ($matches[1] ?? ''));
            }
        } else {
            $normalized = trim((string) ($matches[1] ?? ''));
        }

        if ($normalized === '') {
            return '';
        }

        $normalized = $this->normalizeArchivePdfMonthYear($normalized);
        $normalized = $this->normalizeArchivePdfYearToken($normalized);

        if (preg_match('/^([A-Za-z]+)\s+(\d{4})$/', $normalized, $matches) === 1) {
            return sprintf('%s 1, %s', $matches[1], $matches[2]);
        }

        return str_replace('.', '', $normalized);
    }

    private function normalizeArchivePdfDayToken(string $value): ?int
    {
        $normalized = preg_replace('/[^0-9]/', '', $value) ?? '';

        if ($normalized === '') {
            return null;
        }

        $day = (int) $normalized;

        if ($day >= 1 && $day <= 31) {
            return $day;
        }

        $firstDigit = (int) substr($normalized, 0, 1);

        return ($firstDigit >= 1 && $firstDigit <= 9) ? $firstDigit : null;
    }

    private function normalizeArchivePdfMonthYear(string $value): string
    {
        $normalized = str_replace('.', '', trim($value));

        return str_ireplace(
            ['Mareh ', 'Jam ', 'Januaty ', 'Februaty ', 'Sept ', 'Oet ', 'Dee '],
            ['March ', 'January ', 'January ', 'February ', 'September ', 'October ', 'December '],
            $normalized,
        );
    }

    private function normalizeArchivePdfYearToken(string $value): string
    {
        return preg_replace_callback('/\b20([0-9A-Za-z]{2})\b/', function (array $matches): string {
            $suffix = strtr($matches[1], [
                'O' => '0',
                'o' => '0',
                'Q' => '0',
                'I' => '1',
                'l' => '1',
                'Z' => '2',
                'z' => '2',
                'S' => '5',
                's' => '5',
                'G' => '6',
                'b' => '6',
                'u' => '6',
                'g' => '9',
            ]);

            return '20'.$suffix;
        }, $value) ?? $value;
    }

    private function resolveDocumentersPublishedAt(Article $article, string $timezone): ?Carbon
    {
        $rawHtml = $article->body?->raw_html;

        if (is_string($rawHtml) && trim($rawHtml) !== '') {
            $resolved = $this->extractDocumentersPublishedAt($rawHtml, $timezone);

            if ($resolved instanceof Carbon) {
                return $resolved;
            }
        }

        $sourceUrl = $this->articleSourceUrl($article);

        if ($sourceUrl === null) {
            return null;
        }

        $html = $this->fetchPageHtml($sourceUrl);

        if ($html === null) {
            return null;
        }

        return $this->extractDocumentersPublishedAt($html, $timezone);
    }

    private function extractDocumentersPublishedAt(string $html, string $timezone): ?Carbon
    {
        $candidate = $this->matchDocumentersCandidate($html)
            ?? $this->matchDocumentersCandidate(strip_tags($html))
            ?? $this->matchDocumentersTitleBannerDate($html);

        if ($candidate === null) {
            return null;
        }

        try {
            return Carbon::parse($this->normalizeDocumentersCandidate($candidate), $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function matchDocumentersCandidate(string $content): ?string
    {
        if (preg_match(self::DOCUMENTERS_DATE_PATTERN, $content, $matches) !== 1) {
            return null;
        }

        $candidate = trim((string) ($matches[1] ?? ''));

        return $candidate === '' ? null : $candidate;
    }

    private function normalizeDocumentersCandidate(string $candidate): string
    {
        $normalized = str_replace('.', '', trim($candidate));

        return str_ireplace(
            ['Jan ', 'Feb ', 'Mar ', 'Apr ', 'Jun ', 'Jul ', 'Aug ', 'Sep ', 'Sept ', 'Oct ', 'Nov ', 'Dec '],
            ['January ', 'February ', 'March ', 'April ', 'June ', 'July ', 'August ', 'September ', 'September ', 'October ', 'November ', 'December '],
            $normalized,
        );
    }

    private function matchDocumentersTitleBannerDate(string $html): ?string
    {
        foreach ([
            '/<title>[^<]*?Meeting\s+(\d{1,2}\/\d{1,2}\/\d{4})<\/title>/i',
            '/id="title"[^>]*>[^<]*?Meeting\s+(\d{1,2}\/\d{1,2}\/\d{4})</i',
        ] as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                $candidate = trim((string) ($matches[1] ?? ''));

                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private function firstAttr(Crawler $crawler, string $selector, string $attr): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $value = $node->first()->attr($attr);

        return is_string($value) && trim($value) !== '' ? $this->normalizeWhitespace($value) : null;
    }

    private function firstText(Crawler $crawler, string $selector): ?string
    {
        $node = $crawler->filter($selector);

        if ($node->count() === 0) {
            return null;
        }

        $value = $this->normalizeWhitespace($node->first()->text(''));

        return $value !== '' ? $value : null;
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function containsExplicitTime(string $value): bool
    {
        return preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $value) === 1;
    }

    private function unresolvedSnippet(Article $article): ?string
    {
        $candidates = [
            $article->body?->cleaned_text,
            $article->body?->raw_text,
            is_string($article->body?->raw_html) ? strip_tags($article->body->raw_html) : null,
            $article->summary,
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $normalized = $this->normalizeWhitespace($candidate);

            if ($normalized !== '') {
                return mb_substr($normalized, 0, 180);
            }
        }

        return null;
    }

    private function matches(Article $article, ?Carbon $publishedAt, ?ArticlePublishedPrecision $precision): bool
    {
        if ($publishedAt === null) {
            return false;
        }

        if (! $article->published_at instanceof Carbon) {
            return false;
        }

        return $article->published_at->copy()->utc()->equalTo($publishedAt->copy()->utc())
            && $article->published_precision === $precision;
    }
}
