<?php

namespace App\Services\Ingestion;

use App\Models\Article;
use InvalidArgumentException;

class Deduplicator
{
    public function findExisting(array $item): ?Article
    {
        $cityId = $item['city_id'] ?? null;

        if (! $cityId) {
            throw new InvalidArgumentException('Missing required field: city_id');
        }

        $source = $item['source'] ?? [];
        $url = $item['canonical_url'] ?? ($source['source_url'] ?? null);
        $sourceUrl = $source['source_url'] ?? null;
        $sourceUid = $source['source_uid'] ?? null;
        $contentHash = $item['content_hash'] ?? null;

        if ($url) {
            $byUrl = Article::where('city_id', $cityId)
                ->where('canonical_url', $url)
                ->first();

            if ($byUrl) {
                return $byUrl;
            }
        }

        if ($sourceUrl) {
            $bySourceUrl = Article::query()
                ->select('articles.*')
                ->join('article_sources', 'article_sources.article_id', '=', 'articles.id')
                ->where('articles.city_id', $cityId)
                ->where('article_sources.city_id', $cityId)
                ->where('article_sources.source_url', $sourceUrl)
                ->orderBy('articles.id')
                ->first();

            if ($bySourceUrl) {
                return $bySourceUrl;
            }
        }

        if ($sourceUid) {
            $bySourceUid = Article::query()
                ->select('articles.*')
                ->join('article_sources', 'article_sources.article_id', '=', 'articles.id')
                ->where('articles.city_id', $cityId)
                ->where('article_sources.city_id', $cityId)
                ->where('article_sources.source_uid', $sourceUid)
                ->orderBy('articles.id')
                ->first();

            if ($bySourceUid) {
                return $bySourceUid;
            }
        }

        if ($contentHash) {
            return Article::where('city_id', $cityId)
                ->where('content_hash', $contentHash)
                ->first();
        }

        return null;
    }
}
