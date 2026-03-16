<?php

namespace App\Services\Ingestion;

use App\Enums\ArticlePublishedPrecision;
use App\Jobs\EnrichArticle;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use App\Models\City;
use App\Services\Articles\ArticleTextService;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ArticleWriter
{
    public function write(array $item, ?Article $existing = null): Article
    {
        $cityId = $item['city_id'] ?? null;
        $title = $this->stringValue($item['title'] ?? null);
        $source = $item['source'] ?? [];
        $sourceUrl = $source['source_url'] ?? null;

        if (! $cityId || ! $title || ! $sourceUrl) {
            throw new InvalidArgumentException('Missing required fields: city_id, title, or source.source_url');
        }

        return DB::transaction(function () use ($item, $existing, $cityId, $title, $source, $sourceUrl) {
            $article = $existing ?? new Article;
            $shouldReindex = false;
            $shouldAnalyze = false;
            $shouldRefreshFromExistingBody = false;
            $publishedAt = array_key_exists('published_at', $item)
                ? $item['published_at']
                : $article->published_at;
            $publishedPrecision = $this->resolvePublishedPrecision($item, $cityId);
            $publishedPrecisionToPersist = $publishedPrecision ?? $article->published_precision;

            $incomingSummary = $this->stringValue($item['summary'] ?? null);
            $existingSummary = $this->stringValue($article->summary);
            $incomingTitle = $this->stringValue($title);
            $existingTitle = $this->stringValue($article->title);

            $summaryToPersist = $incomingSummary ?? $existingSummary;
            $titleToPersist = $incomingTitle ?? $existingTitle;

            if ($existingTitle !== null && $incomingTitle !== null) {
                if (! $this->isWeakTitle($existingTitle) && $this->isWeakTitle($incomingTitle)) {
                    $titleToPersist = $existingTitle;
                }
            }

            $article->fill([
                'city_id' => $cityId,
                'scraper_id' => $item['scraper_id'] ?? null,
                'title' => $titleToPersist,
                'summary' => $summaryToPersist, // may be filled below from cleaned_text
                'published_at' => $publishedAt,
                'published_precision' => $publishedPrecisionToPersist?->value,
                'content_type' => $item['content_type'] ?? 'unknown',
                'status' => $item['status'] ?? 'published',
                'canonical_url' => $item['canonical_url'] ?? null,
                'content_hash' => $item['content_hash'] ?? null,
            ]);

            $article->save();

            $articleBody = $item['body'] ?? null;

            if (is_array($articleBody) && $articleBody !== []) {
                $cleanedText = $articleBody['cleaned_text'] ?? null;

                if (is_string($cleanedText) && trim($cleanedText) !== '') {
                    app(ArticleTextService::class)->refresh($article, cleanedText: $cleanedText);
                }

                $extractedAt = array_key_exists('extracted_at', $articleBody)
                    ? $articleBody['extracted_at']
                    : now();

                ArticleBody::updateOrCreate(
                    ['article_id' => $article->id],
                    [
                        'raw_text' => $articleBody['raw_text'] ?? null,
                        'cleaned_text' => $cleanedText,
                        'raw_html' => $articleBody['raw_html'] ?? null,
                        'lang' => $articleBody['lang'] ?? 'en',
                        'extracted_at' => $extractedAt,
                    ]
                );

                $shouldReindex = true;
                $shouldAnalyze = is_string($cleanedText) && trim($cleanedText) !== '';
            } else {
                $article->loadMissing('body');
                $storedCleanedText = $this->stringValue($article->body?->cleaned_text);
                $shouldRefreshFromExistingBody = $existing !== null && $storedCleanedText !== null;
            }

            ArticleSource::updateOrCreate(
                [
                    'article_id' => $article->id,
                    'source_url' => $sourceUrl,
                ],
                [
                    'city_id' => $cityId,
                    'organization_id' => $source['organization_id'] ?? null,
                    'source_type' => $source['source_type'] ?? 'web',
                    'source_uid' => $source['source_uid'] ?? null,
                    'accessed_at' => $source['accessed_at'] ?? now(),
                ]
            );

            if ($shouldRefreshFromExistingBody) {
                $refreshed = app(ArticleTextService::class)->refresh($article);

                if ($refreshed) {
                    $shouldReindex = true;
                }
            }

            if ($shouldReindex || $shouldAnalyze) {
                DB::afterCommit(function () use ($article, $shouldReindex, $shouldAnalyze) {
                    if ($shouldReindex) {
                        $article->load(['body', 'sources', 'scraper']);
                        $article->searchable();
                    }

                    if ($shouldAnalyze && config('enrichment.enabled', true)) {
                        EnrichArticle::dispatch($article->id);
                    }
                });
            }

            return $article;
        });
    }

    private function isWeakTitle(string $title): bool
    {
        if (preg_match('/^event date:/i', $title) === 1) {
            return true;
        }

        if (preg_match('/published on the city\'?s website/i', $title) === 1) {
            return true;
        }

        if (preg_match('/legalnotice/i', $title) === 1) {
            return true;
        }

        if (preg_match('/(\.(pdf|docx?|txt)|\((pdf|docx?|txt)\))$/i', $title) === 1) {
            return true;
        }

        if (preg_match('/^[A-Z0-9._-]{10,}$/', str_replace(' ', '', $title)) === 1) {
            return true;
        }

        return false;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $value;
    }

    private function resolvePublishedPrecision(array $item, int $cityId): ?ArticlePublishedPrecision
    {
        $incomingPrecision = $item['published_precision'] ?? null;

        if ($incomingPrecision instanceof ArticlePublishedPrecision) {
            return $incomingPrecision;
        }

        if (is_string($incomingPrecision)) {
            return ArticlePublishedPrecision::tryFrom(trim($incomingPrecision));
        }

        $publishedAt = $item['published_at'] ?? null;

        if (! is_string($publishedAt)) {
            return null;
        }

        $trimmed = trim($publishedAt);

        if ($trimmed === '') {
            return null;
        }

        if ($this->stringContainsExplicitTime($trimmed)) {
            return ArticlePublishedPrecision::DateTime;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            return ArticlePublishedPrecision::Date;
        }

        $timezone = City::query()->whereKey($cityId)->value('timezone') ?? config('app.timezone', 'UTC');

        try {
            $parsed = Carbon::parse($trimmed, $timezone);
        } catch (\Throwable) {
            return null;
        }

        if (
            $parsed instanceof CarbonInterface
            && $parsed->hour === 0
            && $parsed->minute === 0
            && $parsed->second === 0
        ) {
            return ArticlePublishedPrecision::Date;
        }

        return ArticlePublishedPrecision::DateTime;
    }

    private function stringContainsExplicitTime(string $value): bool
    {
        return preg_match('/\b\d{1,2}:\d{2}(?::\d{2})?\b/', $value) === 1;
    }
}
