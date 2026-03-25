<?php

namespace App\Services\Chat;

use App\Models\Article;
use App\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ChatUpdatesAnswerService
{
    private const LOCAL_RELEVANCE_MIN = 0.6;

    private const CANDIDATE_LIMIT = 24;

    private const SUMMARY_LIMIT = 3;

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    public function answer(string $question, City $city): array
    {
        $windowDays = $this->updatesWindowDays($question);
        $articles = $this->candidateArticles($city, $windowDays, $question)
            ->sortByDesc(fn (Article $article): float => $this->articleScore($article, $question, $windowDays))
            ->values();

        $selected = $articles
            ->filter(fn (Article $article): bool => $this->articleScore($article, $question, $windowDays) > 0)
            ->take(self::SUMMARY_LIMIT)
            ->values();

        if ($selected->isEmpty()) {
            return $this->fallbackResponse($city, $question, $windowDays);
        }

        $citations = $this->citations($selected);

        return [
            'answer' => $this->buildAnswer($selected, $question, $windowDays),
            'citations' => $citations,
            'city' => [
                'id' => (int) $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => $selected->count(),
                'pages_fetched' => count($citations),
                'cache_hits' => 0,
            ],
        ];
    }

    /**
     * @return Collection<int, Article>
     */
    private function candidateArticles(City $city, int $windowDays, string $question): Collection
    {
        $lookbackDays = max($windowDays * 3, 21);
        $cutoff = now()->subDays($lookbackDays);

        return Article::query()
            ->where('city_id', $city->id)
            ->where('status', 'published')
            ->where(function (Builder $builder) use ($cutoff): void {
                $builder->where('published_at', '>=', $cutoff)
                    ->orWhere(function (Builder $nested) use ($cutoff): void {
                        $nested->whereNull('published_at')
                            ->where('created_at', '>=', $cutoff);
                    });
            })
            ->where(function (Builder $builder): void {
                $builder->whereDoesntHave('analysis')
                    ->orWhereHas('analysis', function (Builder $analysisQuery): void {
                        $analysisQuery->whereNull('coverage_scope')
                            ->orWhere('coverage_scope', '!=', 'national')
                            ->orWhereNull('local_relevance_score')
                            ->orWhere('local_relevance_score', '>=', self::LOCAL_RELEVANCE_MIN);
                    });
            })
            ->with(['sources', 'explainer', 'body', 'analysis'])
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->limit(self::CANDIDATE_LIMIT)
            ->get()
            ->filter(function (Article $article) use ($question): bool {
                $focusTerms = $this->focusTerms($question);

                if ($focusTerms === []) {
                    return true;
                }

                $haystack = $this->articleHaystack($article);

                return collect($focusTerms)->contains(
                    fn (string $term): bool => str_contains($haystack, $term)
                );
            })
            ->values();
    }

    private function articleScore(Article $article, string $question, int $windowDays): float
    {
        $focusTerms = $this->focusTerms($question);
        $haystack = $this->articleHaystack($article);
        $title = mb_strtolower((string) $article->title);
        $timestamp = $this->articleTimestamp($article);
        $score = 0.0;

        if ($timestamp) {
            $daysOld = max(0, now()->diffInDays($timestamp));

            if ($daysOld <= $windowDays) {
                $score += 18.0 - min($daysOld * 2.0, 12.0);
            } else {
                $score -= min(($daysOld - $windowDays) * 1.5, 18.0);
            }
        }

        $focusMatches = 0;

        foreach ($focusTerms as $term) {
            if (str_contains($haystack, $term)) {
                $focusMatches++;
                $score += 4.0;
            }

            if (str_contains($title, $term)) {
                $score += 2.5;
            }
        }

        if ($focusTerms !== [] && $focusMatches === 0) {
            $score -= 18.0;
        }

        if ($this->isServiceAlertQuery($question)) {
            foreach (['alert', 'disruption', 'closure', 'outage', 'trash', 'water', 'utility', 'utilities', 'road', 'roads'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 3.0;
                }
            }
        } else {
            foreach (['update', 'updated', 'announcement', 'announced', 'posted', 'published', 'approved', 'filed', 'deadline', 'decision'] as $term) {
                if (str_contains($haystack, $term)) {
                    $score += 1.5;
                }
            }
        }

        if ($article->explainer?->whats_happening) {
            $score += 2.0;
        } elseif ($article->summary) {
            $score += 1.0;
        }

        return $score;
    }

    /**
     * @param  Collection<int, Article>  $articles
     */
    private function buildAnswer(Collection $articles, string $question, int $windowDays): string
    {
        $intro = $this->isServiceAlertQuery($question)
            ? 'Here are the most relevant local service updates I found right now:'
            : 'Here are the most important local updates I found from the last '.$windowDays.' days:';

        $lines = $articles
            ->map(function (Article $article): string {
                $date = $this->formatDate($article);
                $title = trim((string) $article->title) ?: 'Update';
                $summary = $this->articleSummary($article);

                return '- '.$date.': '.$title.'. '.$summary;
            })
            ->values()
            ->all();

        return implode("\n", array_merge([$intro], $lines));
    }

    private function articleSummary(Article $article): string
    {
        $text = trim((string) ($article->explainer?->whats_happening
            ?? $article->summary
            ?? $article->body?->cleaned_text
            ?? ''));

        if ($text === '') {
            return 'Recent local coverage is available from the linked source.';
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $sentence = preg_split('/(?<=[.?!])\s+/u', $text) ?: [$text];
        $summary = trim((string) ($sentence[0] ?? $text));

        if ($summary === '') {
            $summary = $text;
        }

        $summary = Str::limit($summary, 180, '...');

        return Str::finish(rtrim($summary, '. '), '.');
    }

    private function articleHaystack(Article $article): string
    {
        return mb_strtolower(implode(' ', array_filter([
            (string) $article->title,
            (string) $article->summary,
            (string) ($article->explainer?->whats_happening ?? ''),
            (string) ($article->body?->cleaned_text ?? ''),
        ])));
    }

    /**
     * @return array<int, string>
     */
    private function focusTerms(string $question): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($question)) ?: [];
        $stopwords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'what', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'are', 'is', 'was', 'were', 'can', 'could', 'should', 'would', 'will', 'have',
            'has', 'had', 'into', 'onto', 'about', 'your', 'my', 'our', 'their', 'them', 'they', 'you', 'its',
            'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'if', 'as',
            'city', 'local', 'summarize', 'summary', 'important', 'most', 'last', 'past', 'days', 'day',
            'week', 'weeks', 'month', 'months', 'recent', 'recently', 'new', 'updates', 'update', 'changed',
            'right', 'now', 'wichita', 'residents', 'know',
        ];

        return array_values(array_unique(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= 4 && ! in_array($term, $stopwords, true)
        )));
    }

    private function updatesWindowDays(string $question): int
    {
        $normalized = mb_strtolower($question);

        if (preg_match('/\b(?:last|past)\s+(\d{1,2})\s+days?\b/u', $normalized, $matches) === 1) {
            return max(1, (int) ($matches[1] ?? 7));
        }

        if (preg_match('/\b(?:last|past)\s+(\d{1,2})\s+weeks?\b/u', $normalized, $matches) === 1) {
            return max(1, (int) ($matches[1] ?? 1)) * 7;
        }

        if (preg_match('/\b(?:this|last|past)\s+week\b/u', $normalized) === 1) {
            return 7;
        }

        if (preg_match('/\b(?:this|last|past)\s+month\b/u', $normalized) === 1) {
            return 30;
        }

        return $this->isServiceAlertQuery($question) ? 14 : 7;
    }

    private function isServiceAlertQuery(string $question): bool
    {
        return preg_match('/\b(service alerts?|alerts?|disruptions?|outages?|closures?|utilities?|water|trash|roads?)\b/u', mb_strtolower($question)) === 1;
    }

    private function articleTimestamp(Article $article): ?Carbon
    {
        return $article->published_at ?? $article->created_at;
    }

    private function formatDate(Article $article): string
    {
        $timestamp = $this->articleTimestamp($article);

        return $timestamp ? $timestamp->timezone(config('app.timezone'))->format('M j') : 'Recent';
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citations(Collection $articles): array
    {
        return $articles
            ->map(function (Article $article): ?array {
                $sourceUrl = $article->primarySourceUrl() ?: $article->canonical_url;

                if (! is_string($sourceUrl) || trim($sourceUrl) === '') {
                    return null;
                }

                return [
                    'title' => trim((string) $article->title) ?: 'Update',
                    'source_url' => trim($sourceUrl),
                    'type' => 'html',
                ];
            })
            ->filter()
            ->unique('source_url')
            ->take(self::SUMMARY_LIMIT)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    private function fallbackResponse(City $city, string $question, int $windowDays): array
    {
        $answer = $this->isServiceAlertQuery($question)
            ? 'I could not find active local service alerts or disruptions in the available article sources right now.'
            : 'I could not find enough recent local updates in the available article sources for the last '.$windowDays.' days.';

        return [
            'answer' => $answer,
            'citations' => [],
            'city' => [
                'id' => (int) $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => 0,
                'pages_fetched' => 0,
                'cache_hits' => 0,
            ],
        ];
    }
}
