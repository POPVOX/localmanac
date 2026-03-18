<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\ArticleExplainer;
use App\Services\Articles\ArticleTextService;
use App\Services\Articles\MeetingSummaryFallback;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillMeetingSummaries extends Command
{
    protected $signature = 'articles:backfill-meeting-summaries {--city=} {--limit=}';

    protected $description = 'Backfill meeting explainer narrative for existing articles';

    public function handle(MeetingSummaryFallback $fallback, ArticleTextService $textService): int
    {
        $query = $this->eligibleArticlesQuery();
        $limit = $this->option('limit');
        $processed = 0;
        $updated = 0;

        $processor = function ($articles) use ($fallback, $textService, &$processed, &$updated): void {
            foreach ($articles as $article) {
                $processed++;

                if ($this->backfillArticle($article, $fallback, $textService)) {
                    $updated++;
                }
            }
        };

        if ($limit) {
            $processor($query->limit((int) $limit)->get());
        } else {
            $query->chunkById(100, $processor);
        }

        $this->info("Backfilled {$updated} of {$processed} article(s).");

        return self::SUCCESS;
    }

    private function eligibleArticlesQuery(): Builder
    {
        $cityOption = $this->option('city');

        $query = Article::query()
            ->with(['body', 'explainer'])
            ->whereHas('body', function ($query): void {
                $query->whereNotNull('cleaned_text')
                    ->whereRaw('length(trim(cleaned_text)) > 0');
            })
            ->orderBy('id');

        if ($cityOption) {
            if (is_numeric($cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function ($query) use ($cityOption): void {
                    $query->where('slug', (string) $cityOption);
                });
            }
        }

        return $query;
    }

    private function backfillArticle(
        Article $article,
        MeetingSummaryFallback $fallback,
        ArticleTextService $textService,
    ): bool {
        $narrative = $fallback->narrative(
            title: $article->title,
            cleanedText: $article->body?->cleaned_text,
            whatsHappening: $article->explainer?->whats_happening,
            whyItMatters: $article->explainer?->why_it_matters,
        );

        $whatsHappening = $narrative['whats_happening'];
        $whyItMatters = $narrative['why_it_matters'];

        if ($whatsHappening === null && $whyItMatters === null) {
            return $textService->refresh($article);
        }

        $explainer = $article->explainer;

        $explainerUpdated = false;

        if ($explainer !== null) {
            $changes = [];

            if ($whatsHappening !== $explainer->whats_happening) {
                $changes['whats_happening'] = $whatsHappening;
            }

            if ($whyItMatters !== $explainer->why_it_matters) {
                $changes['why_it_matters'] = $whyItMatters;
            }

            if ($changes !== []) {
                if ($explainer->source === null) {
                    $changes['source'] = 'meeting_summary_fallback';
                }

                $explainer->fill($changes)->save();
                $explainerUpdated = true;
            }
        } else {
            ArticleExplainer::query()->create([
                'article_id' => $article->id,
                'city_id' => $article->city_id,
                'whats_happening' => $whatsHappening,
                'why_it_matters' => $whyItMatters,
                'source' => 'meeting_summary_fallback',
            ]);
            $explainerUpdated = true;
        }

        $article->unsetRelation('explainer');
        $article->load('explainer');

        $textUpdated = $textService->refresh($article);

        return $explainerUpdated || $textUpdated;
    }
}
