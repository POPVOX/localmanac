<?php

namespace App\Services\Extraction;

use App\Jobs\EnrichArticle;
use App\Models\Article;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Throwable;

class EnrichmentDispatcher
{
    public function dispatch(int $articleId): bool
    {
        if (! config('enrichment.enabled', true)) {
            return false;
        }

        $article = Article::with(['body', 'analysis', 'city', 'scraper.organization'])->find($articleId);
        if (! $article || $article->analysis?->enrichment_input_hash === app(EnrichmentFingerprint::class)->forArticle($article)) {
            return false;
        }

        // Separate from Laravel's ownerless unique-job lock: an older retry must
        // never be able to release a newer pending dispatch's reservation.
        $lock = Cache::store(config('enrichment.lock_store', 'database'))
            ->lock('enrichment-pending:'.$articleId, 604800);
        if (! $lock->get()) {
            return false;
        }

        try {
            Bus::dispatch(new EnrichArticle($articleId, $lock->owner()));
        } catch (Throwable $exception) {
            $lock->release();
            throw $exception;
        }

        return true;
    }
}
