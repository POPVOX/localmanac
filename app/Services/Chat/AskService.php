<?php

namespace App\Services\Chat;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AskService
{
    public function __construct(
        private readonly ChatSourceSelector $selector,
        private readonly AnswerSynthesizer $synthesizer,
    ) {}

    /**
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    public function answer(
        string $question,
        ?int $cityId = null,
        ?string $citySlug = null,
    ): array {
        $question = trim($question);
        $city = $this->resolveCity($cityId, $citySlug);
        $normalizedQuestion = $this->normalizeQuestionForCity($question, $city);
        $sources = $this->selector->select($city->id, $normalizedQuestion);

        if ($sources->isEmpty()) {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'sources_empty');

            return $fallback;
        }

        try {
            $answerPayload = $this->synthesizer->synthesize(
                question: $normalizedQuestion,
                city: $city,
                sources: $sources,
                originalQuestion: $question,
            );
        } catch (\Throwable) {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'synthesizer_exception');

            return $fallback;
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);
        $confidence = $this->normalizeConfidence($answerPayload['confidence'] ?? 0.0);
        $answerIsNoAnswer = $this->isNoAnswerMessage($answer);
        $answerIsRefusal = $this->isRefusalMessage($answer);

        if ($answerIsRefusal) {
            return [
                'answer' => $answer,
                'citations' => [],
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
            $sourcesSuppressed = $citations !== [];
            $citations = [];
        }

        if ($answerIsNoAnswer || $answer === '') {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, $confidence, 'fallback', $sourcesSuppressed, 'no_grounded_answer');

            return $fallback;
        }

        $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, $confidence, 'answer', $sourcesSuppressed, null);

        return [
            'answer' => $answer,
            'citations' => $citations,
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
    ): array {
        $question = trim($question);
        $city = $this->resolveCityFromSelector($citySelector);
        $normalizedQuestion = $this->normalizeQuestionForCity($question, $city);
        $sources = $this->selector->select($city->id, $normalizedQuestion);

        if ($sources->isEmpty()) {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'sources_empty');

            return array_merge($fallback, ['conversation_id' => $conversationId]);
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
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'streaming_synthesizer_exception');

            return array_merge($fallback, ['conversation_id' => $conversationId]);
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);
        $confidence = $this->normalizeConfidence($answerPayload['confidence'] ?? 0.0);
        $answerIsNoAnswer = $this->isNoAnswerMessage($answer);
        $answerIsRefusal = $this->isRefusalMessage($answer);
        $resolvedConversationId = is_string($answerPayload['conversation_id'] ?? null)
            ? $answerPayload['conversation_id']
            : $conversationId;

        if ($answerIsRefusal) {
            return [
                'answer' => $answer,
                'citations' => [],
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
                'conversation_id' => $resolvedConversationId,
            ];
        }

        $sourcesSuppressed = false;

        if (! $this->shouldSurfaceSources($confidence, $citations)) {
            $sourcesSuppressed = $citations !== [];
            $citations = [];
        }

        if ($answerIsNoAnswer || $answer === '') {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, $confidence, 'fallback', $sourcesSuppressed, 'no_grounded_answer');

            return array_merge($fallback, ['conversation_id' => $resolvedConversationId]);
        }

        $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, $confidence, 'answer', $sourcesSuppressed, null);

        return [
            'answer' => $answer,
            'citations' => $citations,
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
            'conversation_id' => $resolvedConversationId,
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
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    private function resolveFallbackResponse(City $city, Collection $sources): array
    {
        return [
            'answer' => __('I could not find the answer in the sources I checked. Try a different wording or a more specific question.'),
            'citations' => [],
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
        ?string $fallbackReason,
    ): void {
        if (! $sourcesSuppressed && $outcome !== 'fallback') {
            return;
        }

        Log::info('chat.answer.diagnostics', [
            'city_id' => $city->id,
            'city_slug' => $city->slug,
            'question' => $normalizedQuestion,
            'original_question' => $originalQuestion,
            'normalized_question' => $normalizedQuestion,
            'outcome' => $outcome,
            'confidence' => $confidence,
            'sources_suppressed' => $sourcesSuppressed,
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
