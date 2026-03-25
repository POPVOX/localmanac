<?php

namespace App\Services\Chat;

use App\Models\Article;
use App\Models\City;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
        $window = $this->resolveUpdatesWindow($question);
        $articles = $this->candidateArticles($city, $window, $question)
            ->sortByDesc(fn (Article $article): float => $this->articleScore($article, $question, $window['window_days']))
            ->values();

        $selected = $articles
            ->filter(fn (Article $article): bool => $this->articleScore($article, $question, $window['window_days']) > 0)
            ->take(self::SUMMARY_LIMIT)
            ->values();

        if ($selected->isEmpty()) {
            return $this->fallbackResponse($city, $question, $window['label']);
        }

        $citations = $this->citations($selected);

        return [
            'answer' => $this->buildAnswer($selected, $question, $window['label']),
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
     * @param  array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     window_days: int
     * }  $window
     * @return Collection<int, Article>
     */
    private function candidateArticles(City $city, array $window, string $question): Collection
    {
        return Article::query()
            ->where('city_id', $city->id)
            ->where('status', 'published')
            ->where(function (Builder $builder) use ($window): void {
                $builder->whereBetween('published_at', [$window['start_at'], $window['end_at']])
                    ->orWhere(function (Builder $nested) use ($window): void {
                        $nested->whereNull('published_at')
                            ->whereBetween('created_at', [$window['start_at'], $window['end_at']]);
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
    private function buildAnswer(Collection $articles, string $question, string $windowLabel): string
    {
        $intro = $this->isServiceAlertQuery($question)
            ? 'Here are the most relevant local service updates I found right now:'
            : 'Here are the most important local updates I found '.$this->updatesWindowLabelForAnswer($windowLabel).':';

        $lines = $articles
            ->map(fn (Article $article): string => $this->formatUpdateLine($article))
            ->values()
            ->all();

        return implode("\n", array_merge([$intro], $lines));
    }

    private function formatUpdateLine(Article $article): string
    {
        $date = $this->formatDate($article);
        $title = $this->cleanTitle((string) $article->title);
        $description = $this->bestReadableSentence([
            (string) ($article->explainer?->whats_happening ?? ''),
            (string) ($article->summary ?? ''),
            (string) ($article->body?->cleaned_text ?? ''),
        ]);
        $meaning = $this->bestReadableSentence([
            (string) ($article->explainer?->why_it_matters ?? ''),
            ...$this->readableSentences((string) ($article->explainer?->whats_happening ?? '')),
            ...$this->readableSentences((string) ($article->body?->cleaned_text ?? '')),
        ], [$description]);

        if ($description === '') {
            $description = 'recent local coverage is available from the linked source';
        }

        $parts = [$title];
        $descriptionClause = $this->sentenceToClause($description);

        if ($descriptionClause !== '' && ! $this->sameMeaning($title, $descriptionClause)) {
            $parts[] = $descriptionClause;
        }

        $meaningClause = $this->meaningClause($meaning);

        if ($meaningClause !== '' && ! $this->sameMeaning($descriptionClause, $meaningClause)) {
            $parts[] = $meaningClause;
        }

        return '- '.$date.': '.implode(', ', array_filter($parts)).'.';
    }

    private function cleanTitle(string $title): string
    {
        $title = $this->cleanFragment($title);

        if ($title === '') {
            return 'Update';
        }

        return preg_replace('/\s+/', ' ', $title) ?? $title;
    }

    /**
     * @param  array<int, string>  $candidates
     * @param  array<int, string>  $exclude
     */
    private function bestReadableSentence(array $candidates, array $exclude = []): string
    {
        foreach ($candidates as $candidate) {
            foreach ($this->readableSentences($candidate) as $sentence) {
                if ($sentence === '') {
                    continue;
                }

                if (collect($exclude)->contains(fn (string $excluded): bool => $this->sameMeaning($excluded, $sentence))) {
                    continue;
                }

                return $sentence;
            }
        }

        return '';
    }

    /**
     * @return array<int, string>
     */
    private function readableSentences(string $text): array
    {
        $text = $this->cleanFragment($text);

        if ($text === '') {
            return [];
        }

        $rawSentences = preg_split('/(?<=[.?!])\s+/u', $text) ?: [$text];

        return collect($rawSentences)
            ->map(fn (string $sentence): string => $this->normalizeSentence($sentence))
            ->filter(fn (string $sentence): bool => $sentence !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSentence(string $sentence): string
    {
        $sentence = $this->cleanFragment($sentence);

        if ($sentence === '' || $this->looksIncomplete($sentence)) {
            return '';
        }

        return rtrim($sentence, '.!? ').'.';
    }

    private function cleanFragment(string $text): string
    {
        $text = trim(strip_tags($text));
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = preg_replace('/\s*[\(\[\{][^)\]\}]*$/u', '', $text) ?? $text;

        while (substr_count($text, '(') > substr_count($text, ')')) {
            $position = strrpos($text, '(');

            if ($position === false) {
                break;
            }

            $text = rtrim(substr($text, 0, $position));
        }

        return trim((string) preg_replace('/[\s,;:\-\/|]+$/u', '', $text));
    }

    private function looksIncomplete(string $sentence): bool
    {
        if ($sentence === '') {
            return true;
        }

        if (preg_match('/\.\.\.$/u', $sentence) === 1) {
            return true;
        }

        if (preg_match('/[(:;\-\/|]$/u', $sentence) === 1) {
            return true;
        }

        if (preg_match('/\b(?:and|or|with|for|to|of|in|on|at|by|from|about|including|during|because|after|before|through)\.?$/iu', $sentence) === 1) {
            return true;
        }

        if (preg_match('/[.?!]$/u', $sentence) !== 1) {
            return str_word_count($sentence) < 7;
        }

        return false;
    }

    private function sentenceToClause(string $sentence): string
    {
        $clause = rtrim($this->cleanFragment($sentence), '.!? ');

        if ($clause === '') {
            return '';
        }

        return lcfirst($clause);
    }

    private function meaningClause(string $sentence): string
    {
        $clause = $this->sentenceToClause($sentence);

        if ($clause === '') {
            return '';
        }

        $clause = preg_replace('/^(?:this|it|that)\s+means\s+/iu', '', $clause) ?? $clause;

        return 'which means '.$clause;
    }

    private function sameMeaning(string $left, string $right): bool
    {
        $normalize = function (string $value): string {
            $value = mb_strtolower($this->cleanFragment($value));
            $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value) ?? $value;

            return trim($value);
        };

        $left = $normalize($left);
        $right = $normalize($right);

        return $left !== '' && $left === $right;
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

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     window_days: int
     * }
     */
    private function resolveUpdatesWindow(string $question): array
    {
        $normalized = mb_strtolower($question);
        $now = now();

        if (preg_match('/\b(?:last|past)\s+(\d{1,2})\s+days?\b/u', $normalized, $matches) === 1) {
            $days = max(1, (int) ($matches[1] ?? 7));

            return $this->rollingUpdatesWindow($now, $days, 'last '.$days.' days');
        }

        if (preg_match('/\b(?:last|past)\s+(\d{1,2})\s+weeks?\b/u', $normalized, $matches) === 1) {
            $weeks = max(1, (int) ($matches[1] ?? 1));

            return $this->rollingUpdatesWindow($now, $weeks * 7, 'last '.$weeks.' weeks');
        }

        if (preg_match('/\bpast\s+week\b/u', $normalized) === 1) {
            return $this->rollingUpdatesWindow($now, 7, 'past week');
        }

        if (preg_match('/\bthis\s+week\b/u', $normalized) === 1) {
            return $this->boundedUpdatesWindow(
                $now->copy()->startOfWeek(Carbon::MONDAY),
                $now->copy(),
                'this week',
            );
        }

        if (preg_match('/\brecently\b/u', $normalized) === 1) {
            return $this->rollingUpdatesWindow($now, 7, 'recently');
        }

        if (preg_match('/\bright\s+now\b/u', $normalized) === 1) {
            return $this->rollingUpdatesWindow($now, 3, 'right now');
        }

        if (preg_match('/\bthis\s+month\b/u', $normalized) === 1) {
            return $this->boundedUpdatesWindow(
                $now->copy()->startOfMonth(),
                $now->copy(),
                'this month',
            );
        }

        if (preg_match('/\b(?:last|past)\s+month\b/u', $normalized) === 1) {
            return $this->rollingUpdatesWindow($now, 30, 'last 30 days');
        }

        return $this->rollingUpdatesWindow(
            $now,
            $this->isServiceAlertQuery($question) ? 14 : 7,
            $this->isServiceAlertQuery($question) ? 'last 14 days' : 'last 7 days',
        );
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     window_days: int
     * }
     */
    private function rollingUpdatesWindow(Carbon $now, int $days, string $label): array
    {
        $days = max(1, $days);
        $start = $now->copy()->subDays($days - 1)->startOfDay();

        return $this->boundedUpdatesWindow($start, $now->copy(), $label);
    }

    /**
     * @return array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     window_days: int
     * }
     */
    private function boundedUpdatesWindow(Carbon $start, Carbon $end, string $label): array
    {
        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return [
            'start_at' => $start,
            'end_at' => $end,
            'label' => $label,
            'window_days' => max(1, $start->diffInDays($end) + 1),
        ];
    }

    private function updatesWindowLabelForAnswer(string $label): string
    {
        if ($label === 'recently' || $label === 'right now') {
            return $label;
        }

        if (str_starts_with($label, 'last ') || str_starts_with($label, 'past ')) {
            return 'from the '.$label;
        }

        return 'for '.$label;
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
    private function fallbackResponse(City $city, string $question, string $windowLabel): array
    {
        $answer = $this->isServiceAlertQuery($question)
            ? 'I could not find active local service alerts or disruptions in the available article sources right now.'
            : 'I could not find enough recent local updates in the available article sources '.$this->updatesWindowLabelForAnswer($windowLabel).'.';

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
