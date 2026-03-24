<?php

namespace App\Services\Chat;

use App\Models\Article;
use App\Models\City;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class AskService
{
    private const WEEKLY_UPDATES_INTENT = 'weekly_updates';

    private const PERMITS_PROJECTS_INTENT = 'permits_projects';

    private const SERVICE_ALERTS_INTENT = 'service_alerts';

    public function __construct(
        private readonly ChatSourceSelector $selector,
        private readonly AnswerSynthesizer $synthesizer,
    ) {}

    /**
     * @return array<int, string>
     */
    public static function allowedFallbackIntents(): array
    {
        return [
            self::WEEKLY_UPDATES_INTENT,
            self::PERMITS_PROJECTS_INTENT,
            self::SERVICE_ALERTS_INTENT,
        ];
    }

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     resources: array<int, array{type: string, label: string, value: string, url: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    public function answer(
        string $question,
        ?int $cityId = null,
        ?string $citySlug = null,
        ?string $fallbackIntent = null,
    ): array {
        $question = trim($question);
        $city = $this->resolveCity($cityId, $citySlug);
        $normalizedQuestion = $this->normalizeQuestionForCity($question, $city);
        $fallbackIntent = $this->normalizeFallbackIntent($fallbackIntent);
        $sources = $this->selector->select($city->id, $normalizedQuestion);

        if ($sources->isEmpty()) {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                0.0,
                'fallback',
                false,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'sources_empty',
            );

            return $fallback['response'];
        }

        try {
            $answerPayload = $this->synthesizer->synthesize(
                question: $normalizedQuestion,
                city: $city,
                sources: $sources,
                originalQuestion: $question,
            );
        } catch (\Throwable) {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                0.0,
                'fallback',
                false,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'synthesizer_exception',
            );

            return $fallback['response'];
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);
        $resources = $this->normalizeResources($answerPayload['resources'] ?? []);
        $confidence = $this->normalizeConfidence($answerPayload['confidence'] ?? 0.0);
        $answerIsNoAnswer = $this->isNoAnswerMessage($answer);
        $answerIsRefusal = $this->isRefusalMessage($answer);

        if ($answerIsRefusal) {
            return [
                'answer' => $answer,
                'citations' => [],
                'resources' => [],
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                ],
                'meta' => [
                    'sources_used' => $sources->count(),
                    'pages_fetched' => 0,
                    'cache_hits' => 0,
                ],
            ];
        }

        $sourcesSuppressed = false;

        if (! $this->shouldSurfaceSources($confidence, $citations)) {
            $sourcesSuppressed = $citations !== [] || $resources !== [];
            $citations = [];
            $resources = [];
        }

        if ($answerIsNoAnswer || $answer === '') {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                $confidence,
                'fallback',
                $sourcesSuppressed,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'no_grounded_answer',
            );

            return $fallback['response'];
        }

        $this->logAnswerDiagnostics(
            $question,
            $normalizedQuestion,
            $city,
            $sources,
            $confidence,
            'answer',
            $sourcesSuppressed,
            $fallbackIntent,
            false,
            null,
        );

        return [
            'answer' => $answer,
            'citations' => $citations,
            'resources' => $resources,
            'city' => [
                'id' => (int) $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => $sources->count(),
                'pages_fetched' => $this->pagesFetchedFromCitations($citations),
                'cache_hits' => 0,
            ],
        ];
    }

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     resources: array<int, array{type: string, label: string, value: string, url: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int},
     *     conversation_id: string|null
     * }
     */
    public function answerStreamingForUser(
        string $question,
        int|string|null $citySelector,
        User $user,
        ?string $conversationId,
        callable $onDelta,
        ?string $fallbackIntent = null,
    ): array {
        $question = trim($question);
        $city = $this->resolveCityFromSelector($citySelector);
        $normalizedQuestion = $this->normalizeQuestionForCity($question, $city);
        $fallbackIntent = $this->normalizeFallbackIntent($fallbackIntent);
        $sources = $this->selector->select($city->id, $normalizedQuestion);

        if ($sources->isEmpty()) {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                0.0,
                'fallback',
                false,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'sources_empty',
            );

            return array_merge($fallback['response'], ['conversation_id' => $conversationId]);
        }

        try {
            $answerPayload = $this->synthesizer->synthesizeStreaming(
                question: $normalizedQuestion,
                city: $city,
                sources: $sources,
                user: $user,
                conversationId: $conversationId,
                onDelta: $onDelta,
                originalQuestion: $question,
            );
        } catch (\Throwable) {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                0.0,
                'fallback',
                false,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'streaming_synthesizer_exception',
            );

            return array_merge($fallback['response'], ['conversation_id' => $conversationId]);
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);
        $resources = $this->normalizeResources($answerPayload['resources'] ?? []);
        $confidence = $this->normalizeConfidence($answerPayload['confidence'] ?? 0.0);
        $answerIsNoAnswer = $this->isNoAnswerMessage($answer);
        $answerIsRefusal = $this->isRefusalMessage($answer);

        if ($answerIsRefusal) {
            return [
                'answer' => $answer,
                'citations' => [],
                'resources' => [],
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                ],
                'meta' => [
                    'sources_used' => $sources->count(),
                    'pages_fetched' => 0,
                    'cache_hits' => 0,
                ],
                'conversation_id' => is_string($answerPayload['conversation_id'] ?? null)
                    ? $answerPayload['conversation_id']
                    : $conversationId,
            ];
        }

        $sourcesSuppressed = false;

        if (! $this->shouldSurfaceSources($confidence, $citations)) {
            $sourcesSuppressed = $citations !== [] || $resources !== [];
            $citations = [];
            $resources = [];
        }

        if ($answerIsNoAnswer || $answer === '') {
            $fallback = $this->resolveFallbackResponse($city, $sources, $fallbackIntent);
            $this->logAnswerDiagnostics(
                $question,
                $normalizedQuestion,
                $city,
                $sources,
                $confidence,
                'fallback',
                $sourcesSuppressed,
                $fallbackIntent,
                $fallback['digest_fallback_used'],
                'no_grounded_answer',
            );

            return array_merge($fallback['response'], [
                'conversation_id' => is_string($answerPayload['conversation_id'] ?? null)
                    ? $answerPayload['conversation_id']
                    : $conversationId,
            ]);
        }

        $this->logAnswerDiagnostics(
            $question,
            $normalizedQuestion,
            $city,
            $sources,
            $confidence,
            'answer',
            $sourcesSuppressed,
            $fallbackIntent,
            false,
            null,
        );

        return [
            'answer' => $answer,
            'citations' => $citations,
            'resources' => $resources,
            'city' => [
                'id' => (int) $city->id,
                'name' => $city->name,
                'slug' => $city->slug,
            ],
            'meta' => [
                'sources_used' => $sources->count(),
                'pages_fetched' => $this->pagesFetchedFromCitations($citations),
                'cache_hits' => 0,
            ],
            'conversation_id' => is_string($answerPayload['conversation_id'] ?? null)
                ? $answerPayload['conversation_id']
                : $conversationId,
        ];
    }

    private function resolveCity(?int $cityId, ?string $citySlug): City
    {
        if ($cityId) {
            $city = City::query()->find($cityId);

            if ($city) {
                return $city;
            }
        }

        if ($citySlug) {
            $city = City::query()->where('slug', $citySlug)->first();

            if ($city) {
                return $city;
            }
        }

        $city = City::query()->where('slug', 'wichita')->first()
            ?? City::query()->first();

        if (! $city) {
            throw new RuntimeException('No city configured.');
        }

        return $city;
    }

    private function resolveCityFromSelector(int|string|null $citySelector): City
    {
        if (is_int($citySelector)) {
            return $this->resolveCity($citySelector, null);
        }

        if (is_string($citySelector) && trim($citySelector) !== '') {
            return $this->resolveCity(null, $citySelector);
        }

        return $this->resolveCity(null, null);
    }

    private function normalizeQuestionForCity(string $question, City $city): string
    {
        $question = trim($question);

        if ($question === '') {
            return '';
        }

        $cityName = trim($city->name);

        if ($cityName === '') {
            return $question;
        }

        $normalized = preg_replace('/\bmy city\b/i', $cityName, $question) ?? $question;
        $normalized = preg_replace('/\bthe city\b/i', $cityName, $normalized) ?? $normalized;
        $normalized = preg_replace('/\bour city\b/i', $cityName, $normalized) ?? $normalized;
        $normalized = preg_replace('/\bthis city\b/i', $cityName, $normalized) ?? $normalized;

        return preg_replace('/\s+/', ' ', trim($normalized)) ?? trim($normalized);
    }

    private function normalizeFallbackIntent(?string $fallbackIntent): ?string
    {
        if (! is_string($fallbackIntent) || trim($fallbackIntent) === '') {
            return null;
        }

        $fallbackIntent = trim($fallbackIntent);

        return in_array($fallbackIntent, self::allowedFallbackIntents(), true)
            ? $fallbackIntent
            : null;
    }

    /**
     * @param  array<int, mixed>  $citations
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function normalizeCitations(array $citations): array
    {
        return collect($citations)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $sourceUrl = trim((string) ($item['source_url'] ?? ''));

                return [
                    'title' => trim((string) ($item['title'] ?? 'Source')) ?: 'Source',
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($sourceUrl))) ?: 'html',
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '')
            ->unique('source_url')
            ->take((int) config('chat.link_limit', 6))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $resources
     * @return array<int, array{type: string, label: string, value: string, url: string}>
     */
    private function normalizeResources(array $resources): array
    {
        return collect($resources)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                return [
                    'type' => trim((string) ($item['type'] ?? 'link')) ?: 'link',
                    'label' => trim((string) ($item['label'] ?? 'Resource')) ?: 'Resource',
                    'value' => trim((string) ($item['value'] ?? '')),
                    'url' => trim((string) ($item['url'] ?? '')),
                ];
            })
            ->filter(fn (array $item): bool => $item['value'] !== '' && $item['url'] !== '')
            ->unique(fn (array $item): string => $item['type'].'|'.$item['url'])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     resources: array<int, array{type: string, label: string, value: string, url: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    private function resolveFallbackResponse(City $city, Collection $sources, ?string $fallbackIntent = null): array
    {
        $digest = $this->articleDigestFallback($city, $fallbackIntent);
        $citations = $digest['citations'] ?? [];
        $answer = $digest['answer'] ?? __('I could not find the answer in the sources I checked. Try a different wording or a more specific question.');

        return [
            'response' => [
                'answer' => $answer,
                'citations' => $citations,
                'resources' => [],
                'city' => [
                    'id' => (int) $city->id,
                    'name' => $city->name,
                    'slug' => $city->slug,
                ],
                'meta' => [
                    'sources_used' => $sources->count(),
                    'pages_fetched' => $this->pagesFetchedFromCitations($citations),
                    'cache_hits' => 0,
                ],
            ],
            'digest_fallback_used' => $digest !== null,
        ];
    }

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>
     * }|null
     */
    private function articleDigestFallback(City $city, ?string $intent): ?array
    {
        if ($intent === null) {
            return null;
        }

        $windowDays = 7;
        $header = "Here are the latest local updates in {$city->name} from the last 7 days:";
        $keywords = [];
        $allowAllFallback = true;

        if ($intent === self::PERMITS_PROJECTS_INTENT) {
            $windowDays = 30;
            $header = "Here are recent permits and development project updates in {$city->name}:";
            $keywords = ['permit', 'permits', 'rezoning', 'zoning', 'development', 'project', 'projects', 'planning', 'site plan'];
            $allowAllFallback = false;
        }

        if ($intent === self::SERVICE_ALERTS_INTENT) {
            $windowDays = 14;
            $header = "Here are recent service alerts and disruptions in {$city->name}:";
            $keywords = ['alert', 'alerts', 'disruption', 'road closure', 'closure', 'utility', 'utilities', 'water', 'trash', 'recycling', 'outage'];
            $allowAllFallback = false;
        }

        $entries = $this->recentDigestArticles($city, $windowDays, $keywords, $allowAllFallback)
            ->map(function (Article $article) use ($city): ?array {
                $url = trim((string) ($article->primarySourceUrl() ?: $article->canonical_url ?: ''));

                if ($url === '') {
                    return null;
                }

                return [
                    'line' => $this->articleDigestLine($article, $city),
                    'citation' => [
                        'title' => trim((string) ($article->title ?? 'Source')) ?: 'Source',
                        'source_url' => $url,
                        'type' => $this->inferCitationType($url),
                    ],
                ];
            })
            ->filter(fn (?array $entry): bool => is_array($entry))
            ->unique(fn (array $entry): string => $entry['citation']['source_url'])
            ->take((int) config('chat.link_limit', 6))
            ->values();

        if ($entries->isEmpty()) {
            return null;
        }

        $lines = $entries
            ->pluck('line')
            ->filter(fn ($line): bool => is_string($line) && trim($line) !== '')
            ->values();

        if ($lines->isEmpty()) {
            return null;
        }

        return [
            'answer' => $header."\n- ".$lines->implode("\n- "),
            'citations' => $entries
                ->pluck('citation')
                ->filter(fn ($citation): bool => is_array($citation))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, string>  $keywords
     * @return Collection<int, Article>
     */
    private function recentDigestArticles(City $city, int $windowDays, array $keywords, bool $allowAllFallback): Collection
    {
        $timezone = $city->timezone ?: config('app.timezone', 'UTC');
        $since = Carbon::now($timezone)->subDays($windowDays)->startOfDay()->setTimezone('UTC');

        $articles = Article::query()
            ->where('city_id', $city->id)
            ->where(function ($builder) use ($since): void {
                $builder->where('published_at', '>=', $since)
                    ->orWhere(function ($nested) use ($since): void {
                        $nested->whereNull('published_at')
                            ->where('created_at', '>=', $since);
                    });
            })
            ->with(['sources:id,article_id,source_url'])
            ->limit(80)
            ->get()
            ->sortByDesc(fn (Article $article): int => (int) (($article->published_at ?? $article->created_at)?->getTimestamp() ?? 0))
            ->values();

        if ($keywords === []) {
            return $articles;
        }

        $keywordMatches = $articles
            ->filter(fn (Article $article): bool => $this->articleMatchesKeywords($article, $keywords))
            ->values();

        if ($keywordMatches->isNotEmpty()) {
            return $keywordMatches;
        }

        return $allowAllFallback ? $articles : collect();
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function articleMatchesKeywords(Article $article, array $keywords): bool
    {
        $haystack = mb_strtolower(trim(
            ((string) $article->title).' '.((string) $article->summary)
        ));

        if ($haystack === '') {
            return false;
        }

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function articleDigestLine(Article $article, City $city): string
    {
        $timezone = $city->timezone ?: config('app.timezone', 'UTC');
        $date = ($article->published_at ?? $article->created_at)?->copy()->setTimezone($timezone);
        $dateLabel = $date?->format('M j, Y') ?: 'Recent';
        $title = trim((string) ($article->title ?? 'Local update')) ?: 'Local update';
        $summary = trim((string) ($article->summary ?? ''));

        if ($summary === '') {
            return "{$dateLabel}: {$title}";
        }

        return "{$dateLabel}: {$title} - ".Str::limit($summary, 180);
    }

    /**
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     */
    private function pagesFetchedFromCitations(array $citations): int
    {
        return collect($citations)
            ->pluck('source_url')
            ->filter()
            ->unique()
            ->count();
    }

    private function inferCitationType(string $url): string
    {
        return str_ends_with(mb_strtolower($url), '.pdf') ? 'pdf' : 'html';
    }

    /**
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     */
    private function shouldSurfaceSources(float $confidence, array $citations): bool
    {
        if ($citations === []) {
            return false;
        }

        return $confidence >= (float) config('chat.source_display_min_confidence', 0.85);
    }

    private function normalizeConfidence(mixed $confidence): float
    {
        if (! is_numeric($confidence)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $confidence));
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     */
    private function logAnswerDiagnostics(
        string $originalQuestion,
        string $normalizedQuestion,
        City $city,
        Collection $sources,
        float $confidence,
        string $outcome,
        bool $sourcesSuppressed,
        ?string $fallbackIntent,
        bool $digestFallbackUsed,
        ?string $fallbackReason,
    ): void {
        if (! $this->isProceduralQuestion($normalizedQuestion)
            && ! $sourcesSuppressed
            && $outcome !== 'fallback'
            && $fallbackIntent === null
        ) {
            return;
        }

        Log::info('chat.answer.diagnostics', [
            'city_id' => $city->id,
            'city_slug' => $city->slug,
            'question' => $normalizedQuestion,
            'original_question' => $originalQuestion,
            'normalized_question' => $normalizedQuestion,
            'procedural_question' => $this->isProceduralQuestion($normalizedQuestion),
            'outcome' => $outcome,
            'confidence' => $confidence,
            'sources_suppressed' => $sourcesSuppressed,
            'fallback_intent' => $fallbackIntent,
            'request_origin' => $fallbackIntent !== null ? 'chip' : 'free_form',
            'digest_fallback_used' => $digestFallbackUsed,
            'fallback_reason' => $fallbackReason,
            'selected_sources' => $sources
                ->take(8)
                ->map(fn ($source): array => [
                    'id' => (int) $source->id,
                    'name' => (string) $source->name,
                    'source_url' => (string) $source->source_url,
                ])
                ->values()
                ->all(),
        ]);
    }

    private function isProceduralQuestion(string $question): bool
    {
        $question = mb_strtolower(trim($question));

        if ($question === '') {
            return false;
        }

        if (preg_match('/\b(how do i|how can i|where do i|who do i call|what do i need)\b/i', $question) === 1) {
            return true;
        }

        foreach ([
            'permit',
            'permits',
            'license',
            'licenses',
            'apply',
            'application',
            'demolition',
            'inspection',
            'contractor',
            'historic',
            'review',
            'approval',
            'portal',
        ] as $signal) {
            if (str_contains($question, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function isNoAnswerMessage(string $answer): bool
    {
        $normalized = mb_strtolower(trim($answer));
        $target = mb_strtolower('I could not find the answer in the sources I checked.');

        if ($normalized === '' || $target === '') {
            return false;
        }

        return $normalized === $target || str_starts_with($normalized, $target);
    }

    private function isRefusalMessage(string $answer): bool
    {
        $normalized = mb_strtolower(trim($answer));

        foreach ([
            "i can't assist with that",
            'i cannot assist with that',
            "i can't help with that",
            'i cannot help with that',
            "i'm sorry, but i can't assist with that",
            "i'm sorry, but i cannot assist with that",
            "i’m sorry, but i can't assist with that",
            'i’m sorry, but i cannot assist with that',
        ] as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return true;
            }
        }

        return false;
    }
}
