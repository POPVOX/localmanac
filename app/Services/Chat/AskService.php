<?php

namespace App\Services\Chat;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use RuntimeException;

/**
 * IMPORTANT ARCHITECTURE RULES
 *
 * - This is the ONLY orchestration path for chat.
 * - Keep one public chat entry point — all questions follow a single retrieval-and-synthesis path.
 * - Do NOT introduce analyzer sprawl or extra public pipelines.
 * - ChatSourceSelector = retrieval only for source selection.
 * - AnswerSynthesizer = synthesis for all answers (chunks, articles, events unified).
 *
 * If behavior needs to change, update the prompt or synthesizer,
 * NOT the orchestration flow.
 */
class AskService
{
    public function __construct(
        private readonly ChatSourceSelector $selector,
        private readonly AnswerSynthesizer $synthesizer,
        private readonly ?ConversationStore $conversationStore = null,
    ) {}

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
        $effectiveConversationId = $this->resolveConversationIdForQuestion($question, $conversationId);

        $sources = $this->selector->select($city->id, $normalizedQuestion);

        if ($sources->isEmpty()) {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'sources_empty');

            return array_merge($fallback, ['conversation_id' => $effectiveConversationId]);
        }

        try {
            $answerPayload = $this->synthesizer->synthesizeStreaming(
                question: $normalizedQuestion,
                city: $city,
                sources: $sources,
                user: $user,
                conversationId: $effectiveConversationId,
                onDelta: $onDelta,
                originalQuestion: $question,
            );
        } catch (\Throwable $exception) {
            Log::warning('chat.answer.streaming_failed', [
                'city_id' => $city->id,
                'city_slug' => $city->slug,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($question, $normalizedQuestion, $city, $sources, 0.0, 'fallback', false, 'streaming_synthesizer_exception');

            return array_merge($fallback, ['conversation_id' => $effectiveConversationId]);
        }

        $resolvedConversationId = is_string($answerPayload['conversation_id'] ?? null)
            ? $answerPayload['conversation_id']
            : $effectiveConversationId;

        $final = $this->finalizeAnswer(
            originalQuestion: $question,
            normalizedQuestion: $normalizedQuestion,
            city: $city,
            sources: $sources,
            answerPayload: $answerPayload,
        );

        return array_merge($final, ['conversation_id' => $resolvedConversationId]);
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

        throw new RuntimeException('A city is required for every chat request.');
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

    private function resolveConversationIdForQuestion(string $question, ?string $conversationId): ?string
    {
        if (! (bool) config('chat.memory_enabled', true)) {
            return null;
        }

        if (! is_string($conversationId) || trim($conversationId) === '') {
            return null;
        }

        $latestQuestion = $this->latestQuestionFromConversation($conversationId);

        if ($latestQuestion === null) {
            return null;
        }

        return $this->shouldReuseConversation($question, $latestQuestion)
            ? $conversationId
            : null;
    }

    private function latestQuestionFromConversation(string $conversationId): ?string
    {
        try {
            $messages = ($this->conversationStore ?? app(ConversationStore::class))
                ->getLatestConversationMessages($conversationId, 12);
        } catch (\Throwable $exception) {
            Log::warning('chat.memory.lookup_failed', [
                'conversation_id' => $conversationId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $latestUserMessage = $messages
            ->reverse()
            ->first(fn (Message $message): bool => $message->role === MessageRole::User && trim((string) $message->content) !== '');

        if (! $latestUserMessage instanceof Message) {
            return null;
        }

        $content = trim((string) $latestUserMessage->content);

        return $content !== '' ? $content : null;
    }

    private function shouldReuseConversation(string $question, string $latestQuestion): bool
    {
        $normalizedQuestion = $this->normalizeConversationText($question);
        $normalizedLatestQuestion = $this->normalizeConversationText($latestQuestion);

        if ($normalizedQuestion === '' || $normalizedLatestQuestion === '') {
            return false;
        }

        if ($normalizedQuestion === $normalizedLatestQuestion) {
            return true;
        }

        $questionTokens = $this->significantConversationTokens($normalizedQuestion);
        $latestQuestionTokens = $this->significantConversationTokens($normalizedLatestQuestion);
        $overlap = array_values(array_intersect($questionTokens, $latestQuestionTokens));

        if ($overlap !== []) {
            return true;
        }

        if (! $this->hasFollowUpCue($normalizedQuestion)) {
            return false;
        }

        if ($this->containsContextualReference($normalizedQuestion)) {
            return true;
        }

        if ($questionTokens === [] || count($questionTokens) === 1) {
            return true;
        }

        return $this->tokensAreTemporalOnly($questionTokens);
    }

    private function normalizeConversationText(string $question): string
    {
        $normalized = mb_strtolower($question);
        $normalized = preg_replace('/[^\pL\pN\s]/u', ' ', $normalized) ?? $normalized;

        return preg_replace('/\s+/', ' ', trim($normalized)) ?? trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function significantConversationTokens(string $question): array
    {
        $stopWords = [
            'a', 'about', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'but', 'by', 'can',
            'could', 'did', 'do', 'does', 'for', 'from', 'get', 'had', 'has', 'have', 'how',
            'i', 'if', 'in', 'is', 'it', 'its', 'just', 'me', 'more', 'my', 'of', 'on', 'or',
            'our', 'show', 'tell', 'than', 'that', 'the', 'their', 'them', 'then', 'there',
            'these', 'they', 'this', 'those', 'to', 'up', 'us', 'was', 'we', 'what', 'when',
            'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
        ];

        return collect(preg_split('/\s+/', $question) ?: [])
            ->map(fn (string $token): string => Str::singular(trim($token)))
            ->filter(fn (string $token): bool => $token !== '' && ! in_array($token, $stopWords, true))
            ->values()
            ->all();
    }

    private function hasFollowUpCue(string $question): bool
    {
        $trimmedQuestion = trim($question);

        foreach ([
            'what about',
            'how about',
            'what time',
            'where is',
            'where are',
            'when is it',
            'when are they',
            'tell me more',
            'anything else',
            'any updates',
            'more details',
            'expand on',
        ] as $phrase) {
            if (str_starts_with($trimmedQuestion, $phrase) || str_contains($trimmedQuestion, $phrase)) {
                return true;
            }
        }

        return str_starts_with($trimmedQuestion, 'and ')
            || str_starts_with($trimmedQuestion, 'also ');
    }

    private function containsContextualReference(string $question): bool
    {
        return preg_match('/\b(it|its|that|those|them|they|there|same|another|again|more|else)\b/u', $question) === 1;
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function tokensAreTemporalOnly(array $tokens): bool
    {
        $temporalTokens = [
            'today', 'tomorrow', 'yesterday', 'tonight', 'week', 'weekend', 'month',
            'day', 'next', 'upcoming', 'soon', 'later', 'morning', 'afternoon', 'evening',
        ];

        return collect($tokens)->every(fn (string $token): bool => in_array($token, $temporalTokens, true));
    }

    private function finalizeAnswer(
        string $originalQuestion,
        string $normalizedQuestion,
        City $city,
        Collection $sources,
        array $answerPayload,
    ): array {
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

        if ($answer === '' || $answerIsNoAnswer) {
            $fallback = $this->resolveFallbackResponse($city, $sources);
            $this->logAnswerDiagnostics($originalQuestion, $normalizedQuestion, $city, $sources, $confidence, 'fallback', $sourcesSuppressed, 'no_grounded_answer');

            return $fallback;
        }

        $this->logAnswerDiagnostics($originalQuestion, $normalizedQuestion, $city, $sources, $confidence, 'answer', $sourcesSuppressed, null);

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

        return $confidence >= (float) config('chat.source_display_min_confidence', 0.6);
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

        return str_contains($normalized, 'could not find')
            || str_contains($normalized, 'no information');
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
