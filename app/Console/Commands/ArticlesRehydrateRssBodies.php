<?php

namespace App\Console\Commands;

use App\Jobs\EnrichArticle as EnrichArticleJob;
use App\Jobs\ExtractPdfBody;
use App\Models\Article;
use App\Models\ArticleBody;
use App\Services\Articles\ArticleTextService;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ArticlesRehydrateRssBodies extends Command
{
    protected $signature = 'articles:rehydrate-rss-bodies {--city=} {--article=} {--limit=}';

    protected $description = 'Repair low-quality article bodies, summaries, and explainers';

    public function handle(
        RssCanonicalBodyHydrator $hydrator,
        ArticleTextService $textService,
    ): int {
        $query = $this->eligibleArticlesQuery();
        $limit = $this->option('limit');
        $processed = 0;
        $hydrated = 0;
        $queuedDocumentExtraction = 0;
        $queuedEnrichment = 0;
        $refreshedText = 0;
        $failed = 0;

        $processor = function ($articles) use (
            $hydrator,
            $textService,
            &$processed,
            &$hydrated,
            &$queuedDocumentExtraction,
            &$queuedEnrichment,
            &$refreshedText,
            &$failed
        ): void {
            foreach ($articles as $article) {
                $processed++;

                try {
                    $action = $this->repairArticle($article, $hydrator, $textService);

                    match ($action) {
                        'hydrated' => $hydrated++,
                        'document_extraction' => $queuedDocumentExtraction++,
                        'enrichment' => $queuedEnrichment++,
                        'text_refresh' => $refreshedText++,
                        default => null,
                    };
                } catch (\Throwable $exception) {
                    $failed++;

                    Log::warning('Article repair failed.', [
                        'article_id' => $article->id,
                        'source_url' => $article->primarySourceUrl() ?? $article->canonical_url,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            }
        };

        if ($limit) {
            $processor($query->limit((int) $limit)->get());
        } else {
            $query->chunkById(100, $processor);
        }

        $repaired = $hydrated + $queuedDocumentExtraction + $queuedEnrichment + $refreshedText;

        if ($this->option('article')) {
            $this->info("Repaired {$repaired} of {$processed} article(s).");

            return self::SUCCESS;
        }

        $details = [];

        if ($hydrated > 0) {
            $details[] = "{$hydrated} rehydrated from canonical HTML";
        }

        if ($queuedDocumentExtraction > 0) {
            $details[] = "{$queuedDocumentExtraction} queued for document extraction";
        }

        if ($queuedEnrichment > 0) {
            $details[] = "{$queuedEnrichment} queued for AI enrichment";
        }

        if ($refreshedText > 0) {
            $details[] = "{$refreshedText} refreshed from existing text";
        }

        if ($failed > 0) {
            $details[] = "{$failed} failed";
        }

        $summary = "Repaired {$repaired} of {$processed} candidate article(s).";

        if ($details !== []) {
            $summary .= ' '.implode('; ', $details).'.';
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    private function baseArticlesQuery(): Builder
    {
        $cityOption = $this->option('city');
        $articleOption = $this->option('article');

        $query = Article::query()
            ->with(['analysis', 'body', 'explainer', 'sources'])
            ->orderBy('id');

        if ($articleOption) {
            $query->whereKey((int) $articleOption);
        }

        if ($cityOption) {
            if (is_numeric((string) $cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function (Builder $cityQuery) use ($cityOption): void {
                    $cityQuery->where('slug', (string) $cityOption);
                });
            }
        }

        return $query;
    }

    private function eligibleArticlesQuery(): Builder
    {
        $query = $this->baseArticlesQuery();

        if ($this->option('article')) {
            return $query;
        }

        return $query->where(function (Builder $candidateQuery): void {
            $candidateQuery
                ->where(function (Builder $bodyQuery): void {
                    $bodyQuery
                        ->doesntHave('body')
                        ->orWhereHas('body', function (Builder $articleBodyQuery): void {
                            $articleBodyQuery
                                ->whereNull('cleaned_text')
                                ->orWhereRaw("length(trim(coalesce(cleaned_text, ''))) < 280")
                                ->orWhereIn('extraction_status', ['failed', 'empty']);
                        });
                })
                ->orWhere(function (Builder $qualityQuery): void {
                    $qualityQuery
                        ->whereHas('body', function (Builder $bodyQuery): void {
                            $bodyQuery->whereRaw("length(trim(coalesce(cleaned_text, ''))) >= 280");
                        })
                        ->where(function (Builder $needsQualityRepair): void {
                            $needsQualityRepair
                                ->doesntHave('analysis')
                                ->orDoesntHave('explainer')
                                ->orWhereNull('summary')
                                ->orWhereRaw("length(trim(coalesce(summary, ''))) = 0")
                                ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', ['%various items were discussed%'])
                                ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', ['%important local issues affecting%'])
                                ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', ['%community issues and opportunities%'])
                                ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', ['%focus on important local issues%'])
                                ->orWhereRaw('lower(coalesce(summary, \'\')) like ?', ['%helps residents stay informed about community decisions and local governance%'])
                                ->orWhereRaw("trim(coalesce(summary, '')) like ?", ['%:'])
                                ->orWhereHas('explainer', function (Builder $explainerQuery): void {
                                    $explainerQuery
                                        ->whereNull('whats_happening')
                                        ->orWhereRaw("length(trim(coalesce(whats_happening, ''))) = 0")
                                        ->orWhere('source', '!=', 'analysis_llm')
                                        ->orWhereRaw('lower(coalesce(whats_happening, \'\')) like ?', ['%various items were discussed%'])
                                        ->orWhereRaw('lower(coalesce(whats_happening, \'\')) like ?', ['%important local issues affecting%'])
                                        ->orWhereRaw('lower(coalesce(whats_happening, \'\')) like ?', ['%community issues and opportunities%'])
                                        ->orWhereRaw('lower(coalesce(whats_happening, \'\')) like ?', ['%heard the following items%'])
                                        ->orWhereRaw("trim(coalesce(whats_happening, '')) like ?", ['%:'])
                                        ->orWhereRaw('lower(coalesce(why_it_matters, \'\')) like ?', ['%stay informed%'])
                                        ->orWhereRaw('lower(coalesce(why_it_matters, \'\')) like ?', ['%community decisions%'])
                                        ->orWhereRaw('lower(coalesce(why_it_matters, \'\')) like ?', ['%local governance%']);
                                });
                        });
                });
        });
    }

    private function repairArticle(
        Article $article,
        RssCanonicalBodyHydrator $hydrator,
        ArticleTextService $textService,
    ): ?string {
        $sourceUrl = $article->primarySourceUrl() ?? $article->canonical_url;

        if ($this->isDocumentLike($article, $sourceUrl)) {
            if ($this->needsDocumentExtraction($article)) {
                if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
                    return null;
                }

                ExtractPdfBody::dispatch($article->id, $sourceUrl);

                return 'document_extraction';
            }

            if ($this->shouldQueueEnrichment($article, forceForTarget: (bool) $this->option('article'))) {
                EnrichArticleJob::dispatch($article->id);

                return 'enrichment';
            }

            if (($this->summaryNeedsRefresh($article->summary) || $this->titleNeedsRefresh($article->title))
                && $this->hasUsableBody($article)
                && $textService->refresh($article)) {
                return 'text_refresh';
            }

            return null;
        }

        if ($this->needsHtmlHydration($article, $hydrator, $sourceUrl)) {
            return $this->hydrateArticle($article, $hydrator, $textService, $sourceUrl);
        }

        if ($this->shouldQueueEnrichment($article, forceForTarget: (bool) $this->option('article'))) {
            EnrichArticleJob::dispatch($article->id);

            return 'enrichment';
        }

        if (($this->summaryNeedsRefresh($article->summary) || $this->titleNeedsRefresh($article->title))
            && $this->hasUsableBody($article)
            && $textService->refresh($article)) {
            return 'text_refresh';
        }

        return null;
    }

    private function hydrateArticle(
        Article $article,
        RssCanonicalBodyHydrator $hydrator,
        ArticleTextService $textService,
        ?string $sourceUrl,
    ): ?string {
        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return null;
        }

        $storedBody = $article->body;
        $storedCleanedText = $this->stringValue($storedBody?->cleaned_text);
        $storedRawHtml = $this->stringValue($storedBody?->raw_html);
        $hydratedBody = $hydrator->hydrate($sourceUrl);

        if ($hydratedBody === null) {
            return null;
        }

        $newCleanedText = $this->stringValue($hydratedBody['cleaned_text'] ?? null);

        if ($newCleanedText === null) {
            return null;
        }

        $canonicalUrl = $this->stringValue($hydratedBody['canonical_url'] ?? null) ?? $article->canonical_url ?? $sourceUrl;
        $bodyUpdated = $storedCleanedText !== $newCleanedText
            || $storedRawHtml !== $this->stringValue($hydratedBody['raw_html'] ?? null);

        ArticleBody::updateOrCreate(
            ['article_id' => $article->id],
            [
                'raw_html' => $hydratedBody['raw_html'],
                'raw_text' => $hydratedBody['raw_text'],
                'cleaned_text' => $newCleanedText,
                'lang' => $storedBody?->lang ?? 'en',
                'extracted_at' => now(),
                'extraction_status' => 'success',
                'extraction_error' => null,
                'extraction_meta' => array_filter([
                    'source' => 'rss_canonical_hydrator',
                    'renderer' => $hydratedBody['renderer'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ]
        );

        $article->canonical_url = $canonicalUrl;
        $article->content_hash = hash('sha256', $newCleanedText);
        $article->save();

        $article->unsetRelation('body');
        $article->load('body');

        if (config('enrichment.enabled', true)) {
            EnrichArticleJob::dispatch($article->id);

            return 'hydrated';
        }

        $textUpdated = $textService->refresh($article, cleanedText: $newCleanedText);

        return ($bodyUpdated || $textUpdated) ? 'hydrated' : null;
    }

    private function hasUsableBody(Article $article): bool
    {
        $cleanedText = $this->stringValue($article->body?->cleaned_text);

        if ($cleanedText === null) {
            return false;
        }

        return mb_strlen($cleanedText) >= 280;
    }

    private function needsDocumentExtraction(Article $article): bool
    {
        $status = $this->stringValue($article->body?->extraction_status);

        if (in_array($status, ['failed', 'empty'], true)) {
            return true;
        }

        return ! $this->hasUsableBody($article);
    }

    private function needsHtmlHydration(
        Article $article,
        RssCanonicalBodyHydrator $hydrator,
        ?string $sourceUrl,
    ): bool {
        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return false;
        }

        return $hydrator->shouldHydrate(
            $this->stringValue($article->body?->cleaned_text),
            $this->stringValue($article->summary),
            $this->stringValue($article->body?->raw_html),
            $sourceUrl,
        );
    }

    private function shouldQueueEnrichment(Article $article, bool $forceForTarget = false): bool
    {
        if (! config('enrichment.enabled', true) || ! $this->hasUsableBody($article)) {
            return false;
        }

        if ($forceForTarget) {
            return true;
        }

        if ($article->analysis === null) {
            return true;
        }

        $explainer = $article->explainer;

        if ($explainer === null) {
            return true;
        }

        if ($explainer->source !== 'analysis_llm') {
            return true;
        }

        if ($this->isWeakExplainer($explainer->whats_happening, $explainer->why_it_matters)) {
            return true;
        }

        return $this->summaryNeedsRefresh($article->summary);
    }

    private function isDocumentLike(Article $article, ?string $sourceUrl): bool
    {
        if (in_array($article->content_type, ['pdf', 'doc', 'docx', 'document'], true)) {
            return true;
        }

        foreach ([$sourceUrl, $article->canonical_url] as $url) {
            if ($this->urlLooksDocumentLike($url)) {
                return true;
            }
        }

        foreach ($article->sources as $source) {
            if (in_array($source->source_type, ['pdf', 'doc', 'docx', 'document'], true)) {
                return true;
            }
        }

        return false;
    }

    private function urlLooksDocumentLike(?string $url): bool
    {
        $normalized = mb_strtolower(trim((string) $url));

        if ($normalized === '') {
            return false;
        }

        if (str_contains($normalized, 'archive.aspx?adid=')) {
            return true;
        }

        if (str_contains($normalized, 'documentcenter/view/')) {
            return true;
        }

        if (preg_match('/(?:\.|[-_\/])(pdf|docx?|document)(?:$|[?#])/i', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function isWeakExplainer(?string $whatsHappening, ?string $whyItMatters): bool
    {
        $whatsHappening = mb_strtolower(trim((string) $whatsHappening));
        $whyItMatters = mb_strtolower(trim((string) $whyItMatters));

        if ($whatsHappening === '') {
            return true;
        }

        foreach ([
            'various items were discussed',
            'important local issues affecting',
            'community issues and opportunities',
            'heard the following items',
        ] as $phrase) {
            if (str_contains($whatsHappening, $phrase)) {
                return true;
            }
        }

        if (preg_match('/[:;]$/', $whatsHappening) === 1) {
            return true;
        }

        foreach ([
            'stay informed',
            'community decisions',
            'local governance',
            'community engagement',
            'directly impact local initiatives',
        ] as $phrase) {
            if ($whyItMatters !== '' && str_contains($whyItMatters, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function summaryNeedsRefresh(?string $summary): bool
    {
        $summary = $this->stringValue($summary);

        if ($summary === null) {
            return true;
        }

        $normalized = mb_strtolower($summary);

        foreach ([
            'various items were discussed',
            'important local issues affecting',
            'community issues and opportunities',
            'focus on important local issues',
            'residents should be aware of these discussions',
            'helps residents stay informed about community decisions and local governance',
        ] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        if (preg_match('/\.{3,}$/', $summary) === 1) {
            return true;
        }

        if (preg_match('/[:;]$/', $summary) === 1) {
            return true;
        }

        return mb_strlen($summary) >= 180 && preg_match('/[.!?]["\')\]]?$/', $summary) !== 1;
    }

    private function titleNeedsRefresh(?string $title): bool
    {
        $title = trim((string) $title);

        if ($title === '') {
            return true;
        }

        $length = mb_strlen($title);

        if ($length < 4) {
            return true;
        }

        $digits = preg_match_all('/\d/', $title) ?: 0;
        $letters = preg_match_all('/[A-Za-z]/', $title) ?: 0;
        $total = $digits + $letters;

        if ($letters < 3) {
            return true;
        }

        if ($total > 0 && ($digits / $total) > 0.60) {
            return true;
        }

        return $length >= 80 && preg_match('/\b(is|are|was|were|will)\b/i', $title) === 1;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
