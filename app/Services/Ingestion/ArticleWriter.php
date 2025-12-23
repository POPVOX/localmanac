<?php

namespace App\Services\Ingestion;

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ArticleWriter
{
    public function write(array $item, ?Article $existing = null): Article
    {
        $cityId = $item['city_id'] ?? null;
        $title = $item['title'] ?? null;
        $source = $item['source'] ?? [];
        $sourceUrl = $source['source_url'] ?? null;

        if (! $cityId || ! $title || ! $sourceUrl) {
            throw new InvalidArgumentException('Missing required fields: city_id, title, or source.source_url');
        }

        return DB::transaction(function () use ($item, $existing, $cityId, $title, $source, $sourceUrl) {
            $article = $existing ?? new Article;
            $shouldReindex = false;

            $article->fill([
                'city_id' => $cityId,
                'scraper_id' => $item['scraper_id'] ?? null,
                'title' => $title,
                'summary' => $item['summary'] ?? null, // may be filled below from cleaned_text
                'published_at' => $item['published_at'] ?? null,
                'content_type' => $item['content_type'] ?? 'unknown',
                'status' => $item['status'] ?? 'published',
                'canonical_url' => $item['canonical_url'] ?? null,
                'content_hash' => $item['content_hash'] ?? null,
            ]);

            $article->save();

            $articleBody = $item['body'] ?? null;

            if (is_array($articleBody) && $articleBody !== []) {
                $cleanedText = $articleBody['cleaned_text'] ?? null;

                if (empty($article->summary) && is_string($cleanedText) && trim($cleanedText) !== '') {
                    $article->summary = Str::limit(trim($cleanedText), 200);
                    $article->save();
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

            if ($shouldReindex) {
                DB::afterCommit(function () use ($article) {
                    $article->load(['body', 'sources', 'scraper']);
                    $article->searchable();
                });
            }

            return $article;
        });
    }
}
