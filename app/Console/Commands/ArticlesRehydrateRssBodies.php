<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleBody;
use App\Models\ArticleExplainer;
use App\Services\Articles\ArticleTextService;
use App\Services\Articles\MeetingSummaryFallback;
use App\Services\Ingestion\RssCanonicalBodyHydrator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ArticlesRehydrateRssBodies extends Command
{
    protected $signature = 'articles:rehydrate-rss-bodies {--city=} {--article=} {--limit=}';

    protected $description = 'Repair RSS-backed articles whose stored body is only a teaser or missing';

    public function handle(
        RssCanonicalBodyHydrator $hydrator,
        ArticleTextService $textService,
        MeetingSummaryFallback $fallback,
    ): int {
        $query = $this->eligibleArticlesQuery();
        $limit = $this->option('limit');
        $processed = 0;
        $updated = 0;

        $processor = function ($articles) use ($hydrator, $textService, $fallback, &$processed, &$updated): void {
            foreach ($articles as $article) {
                $processed++;

                if ($this->rehydrateArticle($article, $hydrator, $textService, $fallback)) {
                    $updated++;
                }
            }
        };

        if ($limit) {
            $processor($query->limit((int) $limit)->get());
        } else {
            $query->chunkById(100, $processor);
        }

        $this->info("Rehydrated {$updated} of {$processed} article(s).");

        return self::SUCCESS;
    }

    private function eligibleArticlesQuery(): Builder
    {
        $cityOption = $this->option('city');
        $articleOption = $this->option('article');

        $query = Article::query()
            ->with(['body', 'explainer', 'sources'])
            ->whereHas('sources', function (Builder $sourceQuery): void {
                $sourceQuery->where('source_type', 'rss');
            })
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

    private function rehydrateArticle(
        Article $article,
        RssCanonicalBodyHydrator $hydrator,
        ArticleTextService $textService,
        MeetingSummaryFallback $fallback,
    ): bool {
        $sourceUrl = $article->primarySourceUrl() ?? $article->canonical_url;

        if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
            return false;
        }

        $storedBody = $article->body;
        $storedCleanedText = $this->stringValue($storedBody?->cleaned_text);
        $storedSummary = $this->stringValue($article->summary);
        $storedRawHtml = $this->stringValue($storedBody?->raw_html);

        if (! $hydrator->shouldHydrate($storedCleanedText, $storedSummary, $storedRawHtml, $sourceUrl)) {
            return false;
        }

        $hydratedBody = $hydrator->hydrate($sourceUrl);

        if ($hydratedBody === null) {
            return false;
        }

        $newCleanedText = $this->stringValue($hydratedBody['cleaned_text'] ?? null);

        if ($newCleanedText === null) {
            return false;
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
        $article->load(['body', 'explainer']);

        $explainerUpdated = $this->refreshExplainer($article, $fallback, $newCleanedText);
        $textUpdated = $textService->refresh($article, cleanedText: $newCleanedText);

        return $bodyUpdated || $explainerUpdated || $textUpdated;
    }

    private function refreshExplainer(Article $article, MeetingSummaryFallback $fallback, string $cleanedText): bool
    {
        $article->loadMissing('explainer');

        $narrative = $fallback->narrative(
            title: $article->title,
            cleanedText: $cleanedText,
            whatsHappening: $article->explainer?->whats_happening,
            whyItMatters: $article->explainer?->why_it_matters,
        );

        $whatsHappening = $this->stringValue($narrative['whats_happening'] ?? null);
        $whyItMatters = $this->stringValue($narrative['why_it_matters'] ?? null);

        if ($article->explainer !== null) {
            $changes = [];

            if ($whatsHappening !== $article->explainer->whats_happening) {
                $changes['whats_happening'] = $whatsHappening;
            }

            if ($whyItMatters !== $article->explainer->why_it_matters) {
                $changes['why_it_matters'] = $whyItMatters;
            }

            if ($changes === []) {
                return false;
            }

            if ($article->explainer->source === null) {
                $changes['source'] = 'meeting_summary_fallback';
            }

            $article->explainer->fill($changes)->save();

            return true;
        }

        if ($whatsHappening === null && $whyItMatters === null) {
            return false;
        }

        ArticleExplainer::query()->create([
            'article_id' => $article->id,
            'city_id' => $article->city_id,
            'whats_happening' => $whatsHappening,
            'why_it_matters' => $whyItMatters,
            'source' => 'meeting_summary_fallback',
        ]);

        $article->unsetRelation('explainer');
        $article->load('explainer');

        return true;
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
