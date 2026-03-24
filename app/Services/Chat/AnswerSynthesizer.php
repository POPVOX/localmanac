<?php

namespace App\Services\Chat;

use App\Models\ChatSourceChunk;
use App\Models\City;
use App\Models\EventSource;
use App\Models\User;
use App\Services\Chat\Agents\StreamingChatAnswerAgent;
use App\Services\Chat\Event\EventIntentDetector;
use App\Services\Chat\Event\EventSearchService;
use App\Services\Chat\Event\EventWindowResolver;
use App\Services\Chat\Tools\EventSearchTool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Streaming\Events\ProviderToolEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolResult;
use Laravel\Ai\Tools\SimilaritySearch;

/**
 * IMPORTANT ARCHITECTURE RULES
 *
 * - This class has ONE synthesis path only.
 * - Do NOT add routing, model switching, or prompt branching here.
 * - Event retrieval may enrich evidence, but answer synthesis still stays on the same path.
 * - If behavior needs to change, tighten prompts or grounding instead of adding pipelines.
 */
class AnswerSynthesizer
{
    private const NO_ANSWER_MESSAGE = 'I could not find the answer in the sources I checked.';

    public function __construct(
        private readonly ChatSourceRetriever $chatSourceRetriever,
        private readonly ChatSourceGuard $chatSourceGuard,
        private readonly EventIntentDetector $eventIntentDetector,
        private readonly EventWindowResolver $eventWindowResolver,
        private readonly EventSearchService $eventSearchService,
    ) {}

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     confidence: float,
     *     source_mode: string,
     *     conversation_id: string|null
     * }
     */
    public function synthesizeStreaming(
        string $question,
        City $city,
        Collection $sources,
        User $user,
        ?string $conversationId,
        callable $onDelta,
        ?string $originalQuestion = null,
    ): array {
        $originalQuestion ??= $question;
        $eventContext = $this->resolveEventContext($question, $city);
        $seedEvidence = $this->seedEvidence($sources, $question);
        $seedCitations = $this->citationsFromSeedEvidence($seedEvidence);
        $eventCitations = $this->citationsFromLocalEvents($eventContext['local_events'] ?? []);
        $usedSeedAnswer = false;
        $model = $this->chatModelForQuestion($question, $eventContext);

        $agent = StreamingChatAnswerAgent::make(
            tools: $this->buildTools($city, $sources, $question, $eventContext),
        );

        if ($conversationId) {
            $agent->continue($conversationId, as: $user);
        } else {
            $agent->forUser($user);
        }

        $stream = $agent->stream(
            $this->streamingPrompt($question, $city, $seedEvidence, $eventContext),
            provider: $this->streamProvider(),
            model: $model,
            timeout: (int) config('chat.http_timeout', 20),
        );
        $resolvedConversationId = $conversationId;

        $stream->then(function ($response) use (&$resolvedConversationId): void {
            if (is_string($response->conversationId ?? null)) {
                $resolvedConversationId = $response->conversationId;
            }
        });

        $streamedText = '';

        foreach ($stream as $event) {
            if (! $event instanceof TextDelta) {
                continue;
            }

            $streamedText .= $event->delta;
            $onDelta($event->delta);
        }

        $answer = trim($stream->text ?? $streamedText);
        $citations = [];
        $confidence = 0.0;
        $sourceMode = 'none';

        if ($answer !== '') {
            $toolContext = $this->toolContextFromEvents($stream->events ?? new Collection);
            $toolCitations = $this->citationsFromToolContext($toolContext);

            $citations = $this->groundedCitationCandidates(
                $seedCitations,
                [],
                $toolCitations,
                [],
                $seedEvidence,
                $answer,
                $eventCitations,
                (bool) ($eventContext['intent'] ?? false),
            );
            $sourceMode = $this->detectSourceModeFromCitations($citations, $sources, $city);
        }

        if ($answer !== ''
            && ! $this->isNoAnswerMessage($answer)
            && ! $this->isRefusalMessage($answer)
            && ! $this->isAnswerGrounded($question, $answer, $city, $seedEvidence, $citations)
        ) {
            $answer = self::NO_ANSWER_MESSAGE;
        }

        if (($answer === '' || $this->isNoAnswerMessage($answer)) && $seedEvidence !== []) {
            $seedAnswer = $this->answerFromSeedEvidence($question, $city, $seedEvidence);

            if ($seedAnswer !== ''
                && ! $this->isNoAnswerMessage($seedAnswer)
                && $this->isAnswerGrounded($question, $seedAnswer, $city, $seedEvidence, $seedCitations)
            ) {
                $answer = $seedAnswer;
                $usedSeedAnswer = true;

                if ($streamedText === '') {
                    $onDelta($answer);
                }
            }
        }

        if (($usedSeedAnswer || $citations === []) && $seedCitations !== []) {
            $citations = $seedCitations;
            $confidence = max($confidence, $this->deterministicSourceConfidence());
            $sourceMode = $this->detectSourceModeFromCitations($citations, $sources, $city);
        }

        if (($eventContext['intent'] ?? false) && ($answer === '' || $this->isNoAnswerMessage($answer))) {
            if ((int) ($eventContext['local_total'] ?? 0) > 0 && is_array($eventContext['local_events'] ?? null)) {
                $answer = $this->answerFromLocalEvents($city, $eventContext['window'] ?? null, $eventContext['local_events']);
                $confidence = max($confidence, $this->deterministicSourceConfidence());

                if ($citations === []) {
                    $citations = $this->citationsFromLocalEvents($eventContext['local_events']);
                    $sourceMode = $this->detectSourceModeFromCitations($citations, $sources, $city);
                }
            } else {
                $answer = $this->noEventsFoundMessage($city, $eventContext['window'] ?? null);
            }
        }

        $preliminaryAlignedEvidence = $this->alignedEvidenceForAnswer($question, $answer, $seedEvidence);

        if ($this->shouldConstrainProceduralAnswer($question, $answer, $seedEvidence, $preliminaryAlignedEvidence)) {
            $narrowEvidence = $preliminaryAlignedEvidence !== [] ? $preliminaryAlignedEvidence : $seedEvidence;
            $answer = $this->narrowProceduralAnswerFromEvidence($narrowEvidence);

            if ($seedCitations !== []) {
                $citations = $seedCitations;
                $confidence = max($confidence, $this->deterministicSourceConfidence());
                $sourceMode = $this->detectSourceModeFromCitations($citations, $sources, $city);
            }
        }

        $answer = $this->cleanAnswerText($answer);
        $citationSelection = $this->finalizeCitations(
            question: $question,
            answer: $answer,
            seedEvidence: $seedEvidence,
            candidateCitations: $citations,
        );
        $citations = $citationSelection['citations'];
        $alignedEvidence = $citationSelection['aligned_evidence'];
        $droppedCitations = $citationSelection['dropped'];

        if ($this->isRefusalMessage($answer)) {
            $citations = [];
            $alignedEvidence = [];
        }

        $this->logRetrievalDiagnostics(
            question: $question,
            originalQuestion: $originalQuestion,
            city: $city,
            sources: $sources,
            seedEvidence: $seedEvidence,
            alignedEvidence: $alignedEvidence,
            confidence: $confidence,
            sourceMode: $sourceMode,
            citations: $citations,
            droppedCitations: $droppedCitations,
            answer: $answer,
        );

        return [
            'answer' => $answer,
            'citations' => $citations,
            'confidence' => $confidence,
            'source_mode' => $sourceMode,
            'conversation_id' => $agent->currentConversation() ?? $resolvedConversationId,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<string, mixed>  $eventContext
     */
    private function streamingPrompt(string $question, City $city, array $seedEvidence = [], array $eventContext = []): string
    {
        $webEnabled = $this->isWebSearchEnabledForQuestion($question, $eventContext);
        $eventIntent = (bool) ($eventContext['intent'] ?? false);
        $eventWindow = $eventContext['window'] ?? null;

        $lines = [
            'You are a civic information assistant.',
            'Use available tools to gather evidence before answering.',
            'You must call at least one retrieval tool before your final answer.',
            'Return plain text only. Do not include citation markers, IDs, JSON, or metadata.',
            'If answer support is insufficient, answer exactly: "'.self::NO_ANSWER_MESSAGE.'"',
            'Do not invent facts, URLs, dates, or numbers.',
            'If the evidence includes a phone number, URL, or street address that helps answer the question, include it directly in the answer.',
            'If you tell the user to call, show the actual phone number.',
            'If you tell the user to visit a site or GIS tool, show the exact URL.',
            'If you tell the user to go somewhere, show the exact address when the evidence provides one.',
            'Do not name any department, agency, provider, company, office, or organization unless that exact name appears in retrieved evidence.',
            'If the evidence supports the action but not the responsible entity, say you could not verify the exact organization from the sources.',
            '',
            'Web search available: '.($webEnabled ? 'yes' : 'no'),
            'Event intent detected: '.($eventIntent ? 'yes' : 'no'),
            '',
            'City:',
            $city->name,
            '',
            'Time context:',
            ...$this->temporalContextLines($city),
            '',
            'Question:',
            $question,
        ];

        if ($eventIntent) {
            $lines[] = '';
            $lines[] = 'Use EventSearchTool for local calendar events relevant to the question.';
            $lines[] = 'For mixed questions, answer both event and civic parts in a single response.';
            $lines[] = 'For event-only questions, use a warm conversational tone and highlight the most relevant 3-5 options.';
            $lines[] = 'If no events are available in the requested window, clearly say so and suggest the next 7 days or next weekend.';

            if (is_array($eventWindow)) {
                $lines[] = 'Resolved local event window: '
                    .($eventWindow['start_at'] instanceof Carbon ? $eventWindow['start_at']->toIso8601String() : '')
                    .' to '
                    .($eventWindow['end_at'] instanceof Carbon ? $eventWindow['end_at']->toIso8601String() : '')
                    .' ('.((string) ($eventWindow['label'] ?? 'window')).')';
            }
        }

        if ($seedEvidence !== []) {
            $lines[] = '';
            $lines[] = 'Seed evidence excerpts from local indexing:';
            $lines[] = json_encode($this->compactSeedEvidence($seedEvidence), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[]';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @param  array<string, mixed>|null  $eventContext
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @return array<int, \Laravel\Ai\Contracts\Tool|\Laravel\Ai\Providers\Tools\ProviderTool>
     */
    private function buildTools(City $city, Collection $sources, string $question, ?array $eventContext = null, array $seedEvidence = []): array
    {
        $eventContext = $eventContext ?? $this->resolveEventContext($question, $city);
        $tools = [];

        if ((bool) config('chat.tools.similarity.enabled', true)) {
            $tools[] = $this->localSimilaritySearch($sources);
        }

        if ((bool) ($eventContext['intent'] ?? false)) {
            $tools[] = new EventSearchTool(
                eventSearchService: $this->eventSearchService,
                eventWindowResolver: $this->eventWindowResolver,
                city: $city,
                defaultWindow: $eventContext['window'] ?? null,
            );
        }

        if ($this->isWebSearchEnabledForQuestion($question, $eventContext)) {
            $webSearch = new WebSearch(
                maxSearches: (int) config('chat.tools.web_search.max_searches', 2),
            );

            $allowedDomains = $this->webAllowedDomains($sources, $city, $eventContext);

            if ($allowedDomains !== []) {
                $webSearch->allow($allowedDomains);
            }

            if ((bool) config('chat.tools.web_search.use_city_location', true)) {
                // TODO: Switch to $webSearch->location(...) after laravel/ai fixes
                // WebSearch::location() to return $this in the installed version.
                $configuredCity = config('chat.tools.web_search.location_city');
                $webSearch->city = is_string($configuredCity) && trim($configuredCity) !== ''
                    ? $configuredCity
                    : $city->name;
                $webSearch->region = config('chat.tools.web_search.location_region');
                $webSearch->country = (string) config('chat.tools.web_search.default_country', 'US');
            }

            $tools[] = $webSearch;
        }

        return $tools;
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     */
    private function localSimilaritySearch(Collection $sources): SimilaritySearch
    {
        $sourceIds = $sources->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $minSimilarity = (float) config('chat.tools.similarity.min_similarity', 0.65);
        $limit = (int) config('chat.tools.similarity.limit', 12);

        return (new SimilaritySearch(function (string $queryString) use ($limit, $minSimilarity, $sourceIds) {
            if ($sourceIds === [] || trim($queryString) === '') {
                return collect();
            }

            $results = collect();

            if ($this->canUseVectorSearch()) {
                try {
                    $vectorQuery = $this->similarityBaseQuery($sourceIds)
                        ->whereNotNull('embedding')
                        ->whereVectorSimilarTo('embedding', $queryString, $minSimilarity);

                    $results = $vectorQuery
                        ->limit($limit)
                        ->get();
                } catch (\Throwable) {
                    $results = collect();
                }
            }

            if ($results->isEmpty()) {
                $fallbackQuery = $this->similarityBaseQuery($sourceIds);
                $this->applyLikeSearch($fallbackQuery, $queryString);

                $results = $fallbackQuery
                    ->limit($limit)
                    ->get();
            }

            return $results
                ->map(function (ChatSourceChunk $chunk): array {
                    $url = (string) ($chunk->page?->canonical_url ?: $chunk->page?->url ?: '');

                    return [
                        'id' => 'chunk_'.$chunk->id,
                        'title' => (string) ($chunk->page?->title ?: $chunk->page?->source?->name ?: 'Source'),
                        'source_url' => $url,
                        'type' => (string) ($chunk->page?->content_type ?: $this->inferCitationType($url)),
                        'snippet' => trim((string) $chunk->content),
                    ];
                })
                ->filter(fn (array $item): bool => $item['source_url'] !== '' && $item['snippet'] !== '')
                ->values();
        }))
            ->withDescription('Search the locally indexed city documents and return the most relevant excerpts.');
    }

    /**
     * @param  array<int, int>  $sourceIds
     */
    private function similarityBaseQuery(array $sourceIds): Builder
    {
        return ChatSourceChunk::query()
            ->whereHas('page.source', function (Builder $builder) use ($sourceIds): void {
                $builder->whereIn('id', $sourceIds)
                    ->where('is_active', true);
            })
            ->with([
                'page:id,chat_source_id,url,canonical_url,title,content_type',
                'page.source:id,name',
            ]);
    }

    private function canUseVectorSearch(): bool
    {
        if (! (bool) config('chat.vector_enabled', true)) {
            return false;
        }

        return DB::connection()->getDriverName() === 'pgsql';
    }

    private function applyLikeSearch(Builder $query, string $question): void
    {
        $terms = $this->keywordTerms($question);

        $query->where(function (Builder $builder) use ($terms, $question): void {
            if ($terms === []) {
                $builder->where('content', 'like', '%'.trim($question).'%');

                return;
            }

            foreach ($terms as $term) {
                $builder->orWhere('content', 'like', '%'.$term.'%');
            }
        });
    }

    /**
     * @return array<int, string>
     */
    private function keywordTerms(string $question): array
    {
        return collect(preg_split('/\s+/', mb_strtolower($question)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B.,!?;:\"'`()[]{}"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  iterable<int, mixed>  $rawCitations
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function normalizeCitations(iterable $rawCitations): array
    {
        return collect($rawCitations)
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $sourceUrl = trim((string) ($item['source_url'] ?? $item['url'] ?? ''));
                $title = trim((string) ($item['title'] ?? 'Source')) ?: 'Source';

                return [
                    'title' => $title,
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($sourceUrl))) ?: 'html',
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '' && $this->chatSourceGuard->isAllowedCitation($item['source_url'], $item['title']))
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $metaCitations
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citationsFromMeta(Collection $metaCitations): array
    {
        return collect($metaCitations)
            ->map(function ($citation): ?array {
                $url = trim((string) ($citation->url ?? ''));

                if ($url === '') {
                    return null;
                }

                $title = trim((string) ($citation->title ?? 'Source')) ?: 'Source';

                if (! $this->chatSourceGuard->isAllowedCitation($url, $title)) {
                    return null;
                }

                return [
                    'title' => $title,
                    'source_url' => $url,
                    'type' => $this->inferCitationType($url),
                ];
            })
            ->filter()
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $toolResults
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citationsFromToolResults(Collection $toolResults): array
    {
        $toolContext = [
            'tool_results' => $toolResults
                ->map(fn ($toolResult): array => [
                    'name' => (string) ($toolResult->name ?? ''),
                    'arguments' => $toolResult->arguments ?? [],
                    'result' => $this->normalizeToolResultPayload($toolResult->result ?? null),
                ])
                ->values()
                ->all(),
        ];

        return $this->citationsFromToolContext($toolContext);
    }

    /**
     * @param  Collection<int, mixed>  $events
     * @return array<string, mixed>
     */
    private function toolContextFromEvents(Collection $events): array
    {
        return [
            'tool_results' => $events
                ->filter(fn ($event): bool => $event instanceof ToolResult)
                ->map(function (ToolResult $event): array {
                    return [
                        'name' => $event->toolResult->name,
                        'arguments' => $event->toolResult->arguments,
                        'result' => $this->normalizeToolResultPayload($event->toolResult->result),
                    ];
                })
                ->values()
                ->all(),
            'provider_tool_events' => $events
                ->filter(fn ($event): bool => $event instanceof ProviderToolEvent)
                ->map(fn (ProviderToolEvent $event): array => [
                    'type' => $event->type,
                    'status' => $event->status,
                    'data' => $event->data,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citationsFromToolContext(array $toolContext): array
    {
        $candidates = [];
        $this->collectCitationCandidates($toolContext, $candidates);

        return collect($candidates)
            ->map(function (array $item): array {
                $url = trim((string) ($item['source_url'] ?? $item['url'] ?? ''));
                $title = trim((string) ($item['title'] ?? 'Source')) ?: 'Source';

                return [
                    'title' => $title,
                    'source_url' => $url,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($url))) ?: 'html',
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '' && $this->chatSourceGuard->isAllowedCitation($item['source_url'], $item['title']))
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $candidates
     */
    private function collectCitationCandidates(mixed $payload, array &$candidates): void
    {
        if (is_object($payload)) {
            $payload = get_object_vars($payload);
        }

        if (! is_array($payload)) {
            return;
        }

        $url = null;

        foreach (['source_url', 'url'] as $key) {
            if (isset($payload[$key]) && is_string($payload[$key]) && filter_var($payload[$key], FILTER_VALIDATE_URL)) {
                $url = $payload[$key];
                break;
            }
        }

        if (is_string($url) && $url !== '') {
            $candidates[] = [
                'title' => (string) ($payload['title'] ?? 'Source'),
                'source_url' => $url,
                'type' => (string) ($payload['type'] ?? $this->inferCitationType($url)),
            ];
        }

        foreach ($payload as $item) {
            $this->collectCitationCandidates($item, $candidates);
        }
    }

    private function normalizeToolResultPayload(mixed $result): mixed
    {
        if (is_array($result)) {
            return $result;
        }

        if (! is_string($result)) {
            return $result;
        }

        $decoded = json_decode($result, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $firstBracket = strpos($result, '[');
        $firstBrace = strpos($result, '{');
        $offsets = array_filter([$firstBracket, $firstBrace], fn ($offset): bool => $offset !== false);

        if ($offsets === []) {
            return $result;
        }

        $start = min($offsets);
        $trimmed = substr($result, $start);
        $decoded = json_decode($trimmed, true);

        return is_array($decoded) ? $decoded : $result;
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $proceduralContext
     * @return array<int, string>
     */
    private function webAllowedDomains(Collection $sources, City $city, array $eventContext, array $proceduralContext = []): array
    {
        return (bool) ($eventContext['intent'] ?? false)
            ? $this->eventWebAllowedDomains($city)
            : [];
    }

    /**
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $proceduralContext
     */
    private function isWebSearchEnabledForQuestion(string $question, array $eventContext = [], array $proceduralContext = []): bool
    {
        if (! (bool) config('chat.tools.web_search.enabled', true)) {
            return false;
        }

        if (! (bool) ($eventContext['intent'] ?? false)) {
            return false;
        }

        if (! (bool) config('chat.events.web_fallback.enabled', true)) {
            return false;
        }

        if ((bool) config('chat.events.web_fallback.only_when_local_empty', true)) {
            return ((int) ($eventContext['local_total'] ?? 0)) === 0;
        }

        return true;
    }

    private function isProceduralQuestion(string $question): bool
    {
        return preg_match(
            '/\b(how do i|how to|steps?|process|procedure|apply|application|obtain|get|renew|register|file|submit|request|schedule|report|permit|license)\b/i',
            $question
        ) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     */
    private function evidenceLooksGeneric(array $seedEvidence): bool
    {
        if ($seedEvidence === []) {
            return true;
        }

        $proceduralHits = 0;
        $genericHits = 0;

        foreach ($seedEvidence as $item) {
            $haystack = mb_strtolower(implode(' ', [
                (string) ($item['title'] ?? ''),
                (string) ($item['snippet'] ?? ''),
                (string) ($item['source_url'] ?? ''),
            ]));

            foreach ($this->proceduralSignals() as $signal) {
                if (str_contains($haystack, $signal)) {
                    $proceduralHits++;
                }
            }

            foreach ($this->genericEvidenceSignals() as $signal) {
                if (str_contains($haystack, $signal)) {
                    $genericHits++;
                }
            }
        }

        return $proceduralHits < 3 || $genericHits > $proceduralHits;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     */
    private function evidenceLooksOffTarget(string $question, array $seedEvidence): bool
    {
        if ($seedEvidence === []) {
            return true;
        }

        $focusTerms = $this->proceduralFocusTerms($question);

        if ($focusTerms === []) {
            return false;
        }

        $matchingEvidence = collect($seedEvidence)
            ->filter(function (array $item) use ($focusTerms): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($item['title'] ?? ''),
                    (string) ($item['snippet'] ?? ''),
                    (string) ($item['source_url'] ?? ''),
                ]));

                foreach ($focusTerms as $term) {
                    if (str_contains($haystack, $term)) {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        if ($matchingEvidence === 0) {
            return true;
        }

        $topEvidence = array_slice($seedEvidence, 0, min(4, count($seedEvidence)));
        $topMatches = collect($topEvidence)
            ->filter(function (array $item) use ($focusTerms): bool {
                $haystack = mb_strtolower(implode(' ', [
                    (string) ($item['title'] ?? ''),
                    (string) ($item['snippet'] ?? ''),
                    (string) ($item['source_url'] ?? ''),
                ]));

                foreach ($focusTerms as $term) {
                    if (str_contains($haystack, $term)) {
                        return true;
                    }
                }

                return false;
            })
            ->count();

        if ($topMatches === 0) {
            return true;
        }

        return ($matchingEvidence / max(count($seedEvidence), 1)) < 0.35;
    }

    /**
     * @return array<int, string>
     */
    private function proceduralFocusTerms(string $question): array
    {
        $ignored = [
            'how', 'what', 'when', 'where', 'which', 'who', 'does', 'need', 'want',
            'apply', 'application', 'obtain', 'get', 'renew', 'register', 'file',
            'submit', 'request', 'schedule', 'report', 'permit', 'permits',
            'license', 'licenses', 'process', 'procedure', 'steps', 'step', 'city',
        ];

        return collect($this->keywordTerms($question))
            ->reject(fn (string $term): bool => in_array($term, $ignored, true))
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function proceduralSignals(): array
    {
        return [
            'apply',
            'application',
            'submit',
            'submitted',
            'approval',
            'approved',
            'inspection',
            'inspections',
            'required',
            'requirements',
            'document',
            'documents',
            'portal',
            'review',
            'certificate',
            'fee',
            'fees',
            'bond',
            'before',
            'after',
            'then',
            'next',
            'finally',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function genericEvidenceSignals(): array
    {
        return [
            'frequently asked questions',
            'faq',
            'quick links',
            'archive center',
            'boards and committees',
            'news flash',
            'all content',
            'facebook',
            'twitter',
            'pinterest',
            'linkedin',
        ];
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     */
    private function detectSourceModeFromCitations(array $citations, Collection $sources, ?City $city = null): string
    {
        if ($citations === []) {
            return 'none';
        }

        $localHosts = $sources
            ->pluck('source_url')
            ->map(fn ($url): ?string => is_string($url) ? parse_url($url, PHP_URL_HOST) : null)
            ->filter()
            ->values()
            ->merge($city ? $this->eventSourceDomains($city) : collect())
            ->unique()
            ->values();

        $hasLocal = false;
        $hasWeb = false;

        foreach ($citations as $citation) {
            $host = parse_url((string) ($citation['source_url'] ?? ''), PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                continue;
            }

            if ($localHosts->contains($host)) {
                $hasLocal = true;
            } else {
                $hasWeb = true;
            }
        }

        if ($hasLocal && $hasWeb) {
            return 'hybrid';
        }

        if ($hasWeb) {
            return 'web';
        }

        return 'local';
    }

    private function inferCitationType(string $url): string
    {
        return str_ends_with(mb_strtolower($url), '.pdf') ? 'pdf' : 'html';
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function seedEvidence(Collection $sources, string $question): array
    {
        try {
            $retrieved = $this->chatSourceRetriever->retrieve($sources, $question);
        } catch (\Throwable) {
            return [];
        }

        return collect($retrieved['evidence'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->map(function (array $item): array {
                $snippet = trim((string) ($item['snippet'] ?? ''));
                $sourceUrl = trim((string) ($item['source_url'] ?? ''));

                return [
                    'id' => (string) ($item['id'] ?? ''),
                    'title' => trim((string) ($item['title'] ?? 'Source')) ?: 'Source',
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($sourceUrl))) ?: 'html',
                    'snippet' => $snippet,
                    'score' => (float) ($item['score'] ?? 0),
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '' && $item['snippet'] !== '')
            ->take((int) config('chat.retrieval_chunk_limit', 8))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citationsFromSeedEvidence(array $seedEvidence): array
    {
        return collect($seedEvidence)
            ->map(function (array $item): array {
                $sourceUrl = trim((string) ($item['source_url'] ?? ''));
                $title = trim((string) ($item['title'] ?? 'Source')) ?: 'Source';

                return [
                    'title' => $title,
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($sourceUrl))) ?: 'html',
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '' && $this->chatSourceGuard->isAllowedCitation($item['source_url'], $item['title']))
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array{title: string, source_url: string, type: string}>  $seedCitations
     * @param  array<int, array{title: string, source_url: string, type: string}>  $metaCitations
     * @param  array<int, array{title: string, source_url: string, type: string}>  $toolCitations
     * @param  array<int, array{title: string, source_url: string, type: string}>  $modelCitations
     * @param  array<int, array{title: string, source_url: string, type: string}>  $eventCitations
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function groundedCitationCandidates(
        array $seedCitations,
        array $metaCitations,
        array $toolCitations,
        array $modelCitations,
        array $seedEvidence,
        string $answer,
        array $eventCitations = [],
        bool $allowModelOnlyCitations = false,
    ): array {
        $supportedUrls = collect($seedEvidence)
            ->pluck('source_url')
            ->merge(collect($seedCitations)->pluck('source_url'))
            ->merge(collect($metaCitations)->pluck('source_url'))
            ->merge(collect($toolCitations)->pluck('source_url'))
            ->merge(collect($eventCitations)->pluck('source_url'))
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => trim($url))
            ->unique()
            ->values();

        $keepGenericFallback = $seedEvidence === []
            || $this->isNoAnswerMessage($answer)
            || trim($answer) === '';

        return collect(array_merge($seedCitations, $metaCitations, $toolCitations, $modelCitations))
            ->filter(function (array $citation) use ($supportedUrls, $keepGenericFallback): bool {
                $url = trim((string) ($citation['source_url'] ?? ''));

                if ($url === '') {
                    return false;
                }

                if (! $supportedUrls->contains($url)) {
                    return false;
                }

                if ($keepGenericFallback) {
                    return true;
                }

                return ! $this->isGenericCitation($citation);
            })
            ->when(
                $allowModelOnlyCitations && $supportedUrls->isEmpty(),
                fn ($collection) => $collection->merge(
                    collect($modelCitations)->reject(fn (array $citation): bool => $this->isGenericCitation($citation))
                )
            )
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @return array<int, array{title: string, source_url: string, snippet: string}>
     */
    private function compactSeedEvidence(array $seedEvidence): array
    {
        $maxSnippetChars = max(600, (int) config('chat.chunk_max_chars', 1200));

        return collect($seedEvidence)
            ->map(fn (array $item): array => [
                'title' => (string) ($item['title'] ?? 'Source'),
                'source_url' => (string) ($item['source_url'] ?? ''),
                'snippet' => mb_substr((string) ($item['snippet'] ?? ''), 0, $maxSnippetChars),
            ])
            ->filter(fn (array $item): bool => $item['source_url'] !== '' && $item['snippet'] !== '')
            ->take(6)
            ->values()
            ->all();
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

    private function normalizedConfidence(mixed $confidence): float
    {
        if (! is_numeric($confidence)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $confidence));
    }

    private function deterministicSourceConfidence(): float
    {
        return max((float) config('chat.source_display_min_confidence', 0.85), 0.9);
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     */
    private function answerFromSeedEvidence(string $question, City $city, array $seedEvidence): string
    {
        if ($seedEvidence === []) {
            return '';
        }

        $prompt = implode("\n", [
            'You are a civic information assistant.',
            'Answer only from the provided evidence excerpts.',
            'Do not invent facts, URLs, dates, numbers, or contacts.',
            'If the evidence includes a phone number, URL, or street address that helps answer the question, include it directly in the answer.',
            'If you tell the user to call, show the actual phone number.',
            'If you tell the user to visit a site or GIS tool, show the exact URL.',
            'If you tell the user to go somewhere, show the exact address when the evidence provides one.',
            'Do not name any department, agency, provider, company, office, or organization unless that exact name appears in the evidence excerpts.',
            'If the evidence supports the action but not the responsible entity, say you could not verify the exact organization from the sources.',
            'If the evidence is insufficient, answer exactly: "'.self::NO_ANSWER_MESSAGE.'"',
            '',
            'City:',
            $city->name,
            '',
            'Time context:',
            ...$this->temporalContextLines($city),
            '',
            'Question:',
            $question,
            '',
            'Evidence excerpts:',
            json_encode($this->compactSeedEvidence($seedEvidence), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '[]',
        ]);

        try {
            $response = StreamingChatAnswerAgent::make(tools: [])->prompt(
                $prompt,
                provider: $this->providerPreference(
                    chainConfigKey: 'chat.provider_chain',
                    fallbackProviderConfigKey: 'chat.provider',
                    model: (string) config('chat.model', config('enrichment.model', 'gpt-4o-mini')),
                ),
                timeout: (int) config('chat.http_timeout', 20),
            );
        } catch (\Throwable) {
            return '';
        }

        return trim((string) ($response->text ?? ''));
    }

    private function phonePattern(): string
    {
        return '/\b\+?1?[\s.\-]?\(?\d{3}\)?[\s.\-]?\d{3}[\s.\-]?\d{4}\b/';
    }

    private function addressPattern(): string
    {
        return '/\b\d{1,5}\s+[A-Z0-9][A-Za-z0-9.#\'\-]*(?:\s+[A-Z0-9][A-Za-z0-9.#\'\-]*){0,8}\s(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln|Court|Ct|Circle|Cir|Way|Terrace|Ter|Place|Pl|Parkway|Pkwy|Highway|Hwy)\b(?:,\s*[A-Za-z .\'\-]+)?(?:,\s*[A-Z]{2})?(?:\s+\d{5}(?:-\d{4})?)?/i';
    }

    private function normalizePhoneNumber(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            return null;
        }

        return $digits;
    }

    private function formatPhoneNumber(string $digits): string
    {
        return sprintf('(%s) %s-%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
    }

    private function cleanAddress(string $address): string
    {
        $address = preg_replace('/\s+/', ' ', trim($address)) ?? trim($address);

        return rtrim($address, '.,;:');
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     */
    private function logRetrievalDiagnostics(
        string $question,
        string $originalQuestion,
        City $city,
        Collection $sources,
        array $seedEvidence,
        array $alignedEvidence,
        float $confidence,
        string $sourceMode,
        array $citations,
        array $droppedCitations,
        string $answer,
    ): void {
        $shouldLog = $this->isNoAnswerMessage($answer)
            || $answer === '';

        if (! $shouldLog) {
            return;
        }

        Log::info('chat.retrieval.diagnostics', [
            'city_id' => $city->id,
            'city_slug' => $city->slug,
            'question' => $question,
            'original_question' => $originalQuestion,
            'selected_sources' => $sources
                ->take(8)
                ->map(fn ($source): array => [
                    'id' => (int) $source->id,
                    'name' => (string) $source->name,
                    'source_url' => (string) $source->source_url,
                    'priority' => (int) $source->priority,
                ])
                ->values()
                ->all(),
            'seed_evidence' => collect($seedEvidence)
                ->take(5)
                ->map(fn (array $item): array => [
                    'title' => (string) ($item['title'] ?? 'Source'),
                    'source_url' => (string) ($item['source_url'] ?? ''),
                    'score' => (float) ($item['score'] ?? 0.0),
                ])
                ->values()
                ->all(),
            'aligned_evidence' => collect($alignedEvidence)
                ->take(5)
                ->map(fn (array $item): array => [
                    'title' => (string) ($item['title'] ?? 'Source'),
                    'source_url' => (string) ($item['source_url'] ?? ''),
                    'alignment_score' => (float) ($item['alignment_score'] ?? 0.0),
                ])
                ->values()
                ->all(),
            'source_mode' => $sourceMode,
            'confidence' => $confidence,
            'citations_count' => count($citations),
            'dropped_citations' => $droppedCitations,
            'no_answer' => $this->isNoAnswerMessage($answer) || $answer === '',
        ]);
    }

    /**
     * @return array{
     *     intent: bool,
     *     window: array{
     *         start_at: Carbon,
     *         end_at: Carbon,
     *         label: string,
     *         is_explicit: bool,
     *         parse_confidence: float
     *     }|null,
     *     local_total: int,
     *     local_events: array<int, array{
     *         title: string,
     *         starts_at: string,
     *         ends_at: string|null,
     *         all_day: bool,
     *         location_name: string|null,
     *         summary: string,
     *         source_url: string,
     *         source_name: string
     *     }>
     * }
     */
    private function resolveEventContext(string $question, City $city): array
    {
        if (! (bool) config('chat.events.enabled', true)) {
            return [
                'intent' => false,
                'window' => null,
                'local_total' => 0,
                'local_events' => [],
            ];
        }

        if ((string) config('chat.events.intent_mode', 'intent') !== 'intent') {
            return [
                'intent' => false,
                'window' => null,
                'local_total' => 0,
                'local_events' => [],
            ];
        }

        if (! $this->eventIntentDetector->isEventIntent($question)) {
            return [
                'intent' => false,
                'window' => null,
                'local_total' => 0,
                'local_events' => [],
            ];
        }

        $timezone = $city->timezone ?: config('app.timezone', 'UTC');
        $window = $this->eventWindowResolver->resolve($question, $timezone);
        $localTotal = 0;
        $localEvents = [];

        try {
            $searchResult = $this->eventSearchService->search(
                city: $city,
                window: $window,
                question: $question,
                limit: (int) config('chat.events.max_results', 8),
            );

            $localTotal = (int) ($searchResult['total'] ?? 0);
            $localEvents = collect($searchResult['events'] ?? [])
                ->filter(fn ($item): bool => is_array($item))
                ->values()
                ->all();

            if ($window === null && is_array($searchResult['window'] ?? null)) {
                $window = $searchResult['window'];
            }
        } catch (\Throwable) {
            $localTotal = 0;
            $localEvents = [];
        }

        return [
            'intent' => true,
            'window' => $window,
            'local_total' => $localTotal,
            'local_events' => $localEvents,
        ];
    }

    /**
     * @param  array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null  $window
     * @param  array<int, array{
     *     title: string,
     *     starts_at: string,
     *     ends_at: string|null,
     *     all_day: bool,
     *     location_name: string|null,
     *     summary: string,
     *     source_url: string,
     *     source_name: string
     * }>  $events
     */
    private function answerFromLocalEvents(City $city, ?array $window, array $events): string
    {
        $windowLabel = is_array($window) && is_string($window['label'] ?? null)
            ? $window['label']
            : 'the requested time period';
        $maxHighlights = max(1, (int) config('chat.events.response_max_highlights', 5));

        $lines = collect($events)
            ->filter(fn (array $event): bool => trim((string) ($event['title'] ?? '')) !== '')
            ->take($maxHighlights)
            ->map(function (array $event) use ($city): string {
                $title = trim((string) ($event['title'] ?? 'Event'));
                $when = trim((string) ($event['starts_at'] ?? ''));
                $location = trim((string) ($event['location_name'] ?? ''));

                if ($when === '') {
                    return $location !== '' ? "{$title} ({$location})" : $title;
                }

                try {
                    $start = Carbon::parse($when)->setTimezone($city->timezone ?: config('app.timezone', 'UTC'));
                    $formatted = (bool) ($event['all_day'] ?? false)
                        ? $start->format('D, M j')
                        : $start->format('D, M j g:i A');
                } catch (\Throwable) {
                    $formatted = $when;
                }

                if ($location !== '') {
                    return "{$title} - {$formatted} at {$location}";
                }

                return "{$title} - {$formatted}";
            })
            ->values();

        if ($lines->isEmpty()) {
            return "I found events in {$city->name} for {$windowLabel}, but I could not format them reliably.";
        }

        return "Top events in {$city->name} for {$windowLabel}:\n- ".$lines->implode("\n- ");
    }

    /**
     * @param  array<int, array{
     *     title: string,
     *     starts_at: string,
     *     ends_at: string|null,
     *     all_day: bool,
     *     location_name: string|null,
     *     summary: string,
     *     source_url: string,
     *     source_name: string
     * }>  $events
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function citationsFromLocalEvents(array $events): array
    {
        return collect($events)
            ->map(function (array $event): array {
                $url = trim((string) ($event['source_url'] ?? ''));

                return [
                    'title' => trim((string) ($event['title'] ?? $event['source_name'] ?? 'Event')) ?: 'Event',
                    'source_url' => $url,
                    'type' => $this->inferCitationType($url),
                ];
            })
            ->filter(fn (array $citation): bool => $citation['source_url'] !== '')
            ->unique('source_url')
            ->take((int) config('chat.link_limit', 6))
            ->values()
            ->all();
    }

    private function cleanAnswerText(string $answer): string
    {
        $answer = str_replace(['**', '__', '`'], '', $answer);
        $answer = preg_replace('/\r\n?/', "\n", $answer) ?? $answer;
        $answer = preg_replace("/\n{3,}/", "\n\n", $answer) ?? $answer;

        return trim($answer);
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     */
    private function isAnswerGrounded(string $question, string $answer, City $city, array $seedEvidence, array $citations): bool
    {
        if ($answer === '' || $this->isNoAnswerMessage($answer) || $this->isRefusalMessage($answer)) {
            return true;
        }

        $supportText = $this->answerSupportText($seedEvidence, $citations);
        $supportHaystack = $this->normalizeSupportText($supportText);
        $supportDigits = preg_replace('/\D+/', '', $supportText) ?? '';

        if ($supportHaystack === '') {
            return false;
        }

        foreach ($this->extractAnswerUrls($answer) as $url) {
            if (! str_contains($supportHaystack, $this->normalizeSupportText($url))) {
                return false;
            }
        }

        foreach ($this->extractAnswerPhones($answer) as $digits) {
            if (! str_contains($supportDigits, $digits)) {
                return false;
            }
        }

        foreach ($this->extractAnswerCurrencyValues($answer) as $value) {
            if (! str_contains($supportHaystack, $this->normalizeSupportText($value))) {
                return false;
            }
        }

        foreach ($this->extractAnswerAddresses($answer) as $address) {
            if (! str_contains($supportHaystack, $this->normalizeSupportText($address))) {
                return false;
            }
        }

        if (! $this->requiresStrictEntityGrounding($question, $answer)) {
            return true;
        }

        foreach ($this->extractReferredEntities($answer, $city) as $entity) {
            if (! str_contains($supportHaystack, $this->normalizeSupportText($entity))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     */
    private function answerSupportText(array $seedEvidence, array $citations): string
    {
        return collect($seedEvidence)
            ->map(fn (array $item): string => implode(' ', [
                (string) ($item['title'] ?? ''),
                (string) ($item['source_url'] ?? ''),
                (string) ($item['snippet'] ?? ''),
            ]))
            ->merge(
                collect($citations)->map(fn (array $citation): string => implode(' ', [
                    (string) ($citation['title'] ?? ''),
                    (string) ($citation['source_url'] ?? ''),
                ]))
            )
            ->implode(' ');
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<int, array{title: string, source_url: string, type: string}>  $candidateCitations
     * @return array{
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     aligned_evidence: array<int, array<string, mixed>>,
     *     dropped: array<int, array{source_url: string, reason: string}>
     * }
     */
    private function finalizeCitations(string $question, string $answer, array $seedEvidence, array $candidateCitations): array
    {
        if ($this->isRefusalMessage($answer)) {
            return [
                'citations' => [],
                'aligned_evidence' => [],
                'dropped' => [],
            ];
        }

        $alignedEvidence = $this->alignedEvidenceForAnswer($question, $answer, $seedEvidence);
        $alignedEvidence = $this->filterProceduralAlignedEvidence($question, $alignedEvidence);
        $dropped = [];
        $alignedUrls = collect($alignedEvidence)
            ->pluck('source_url')
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->unique()
            ->values();

        $evidenceCitations = collect($alignedEvidence)
            ->map(function (array $item): array {
                $sourceUrl = trim((string) ($item['source_url'] ?? ''));

                return [
                    'title' => trim((string) ($item['title'] ?? 'Source')) ?: 'Source',
                    'source_url' => $sourceUrl,
                    'type' => trim((string) ($item['type'] ?? $this->inferCitationType($sourceUrl))) ?: 'html',
                    '_score' => (float) ($item['alignment_score'] ?? $item['score'] ?? 0.0),
                ];
            })
            ->filter(fn (array $item): bool => $item['source_url'] !== '')
            ->sortByDesc('_score')
            ->unique('source_url')
            ->values();

        $preferredCitations = $evidenceCitations
            ->reject(function (array $citation) use (&$dropped): bool {
                $isGeneric = $this->isGenericCitation($citation);

                if ($isGeneric) {
                    $dropped[] = [
                        'source_url' => (string) ($citation['source_url'] ?? ''),
                        'reason' => 'generic_page',
                    ];
                }

                return $isGeneric;
            })
            ->map(function (array $citation): array {
                unset($citation['_score']);

                return $citation;
            })
            ->values();

        $preferredCitations = $this->filterProceduralCitations($question, $preferredCitations, $alignedEvidence, $dropped);

        if ($preferredCitations->isEmpty() && $evidenceCitations->isNotEmpty()) {
            $preferredCitations = $evidenceCitations
                ->map(function (array $citation): array {
                    unset($citation['_score']);

                    return $citation;
                })
                ->values();
        }

        if ($preferredCitations->isEmpty()) {
            $supportedCandidates = collect($candidateCitations)
                ->filter(function (array $citation) use ($alignedUrls, &$dropped): bool {
                    $url = trim((string) ($citation['source_url'] ?? ''));

                    if ($url === '' || ($alignedUrls->isNotEmpty() && ! $alignedUrls->contains($url))) {
                        $dropped[] = [
                            'source_url' => $url,
                            'reason' => 'weak_alignment',
                        ];

                        return false;
                    }

                    if ($this->isGenericCitation($citation) && $alignedUrls->isNotEmpty()) {
                        $dropped[] = [
                            'source_url' => $url,
                            'reason' => 'generic_page',
                        ];

                        return false;
                    }

                    return true;
                })
                ->unique('source_url')
                ->values();

            $supportedCandidates = $this->filterProceduralCitations($question, $supportedCandidates, $alignedEvidence, $dropped);

            if ($supportedCandidates->isEmpty() && $alignedUrls->isEmpty()) {
                $supportedCandidates = collect($candidateCitations)
                    ->filter(function (array $citation) use (&$dropped): bool {
                        $url = trim((string) ($citation['source_url'] ?? ''));

                        if ($url === '') {
                            return false;
                        }

                        if ($this->isGenericCitation($citation)) {
                            $dropped[] = [
                                'source_url' => $url,
                                'reason' => 'generic_page',
                            ];

                            return false;
                        }

                        return true;
                    })
                    ->unique('source_url')
                    ->values();
            }

            if ($supportedCandidates->isEmpty()) {
                $supportedCandidates = collect($candidateCitations)
                    ->filter(fn (array $citation): bool => trim((string) ($citation['source_url'] ?? '')) !== '')
                    ->unique('source_url')
                    ->values();
            }

            $preferredCitations = $supportedCandidates;
        }

        return [
            'citations' => $preferredCitations
                ->take((int) config('chat.link_limit', 6))
                ->values()
                ->all(),
            'aligned_evidence' => $alignedEvidence,
            'dropped' => collect($dropped)
                ->filter(fn (array $item): bool => ($item['source_url'] ?? '') !== '')
                ->unique(fn (array $item): string => $item['source_url'].'|'.$item['reason'])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @return array<int, array<string, mixed>>
     */
    private function alignedEvidenceForAnswer(string $question, string $answer, array $seedEvidence): array
    {
        if ($seedEvidence === []) {
            return [];
        }

        $terms = $this->answerAlignmentTerms($question, $answer);
        $isProcedural = $this->isProceduralQuestion($question);
        $proceduralFocusTerms = $isProcedural ? $this->proceduralFocusTerms($question) : [];

        $ranked = collect($seedEvidence)
            ->map(function (array $item) use ($terms, $answer, $isProcedural, $question, $proceduralFocusTerms): array {
                $snippet = mb_strtolower((string) ($item['snippet'] ?? ''));
                $title = mb_strtolower((string) ($item['title'] ?? ''));
                $url = mb_strtolower((string) ($item['source_url'] ?? ''));
                $score = (float) ($item['score'] ?? 0.0);
                $alignmentScore = 0.0;

                foreach ($terms as $term) {
                    if (str_contains($snippet, $term)) {
                        $alignmentScore += 3.0;
                    }

                    if (str_contains($title, $term)) {
                        $alignmentScore += 1.5;
                    }

                    if (str_contains($url, $term)) {
                        $alignmentScore += 0.5;
                    }
                }

                foreach ($this->extractAnswerPhones($answer) as $digits) {
                    if (str_contains(preg_replace('/\D+/', '', $snippet) ?? '', $digits)) {
                        $alignmentScore += 5.0;
                    }
                }

                foreach ($this->extractAnswerAddresses($answer) as $address) {
                    if (str_contains($this->normalizeSupportText($snippet), $this->normalizeSupportText($address))) {
                        $alignmentScore += 5.0;
                    }
                }

                foreach ($this->extractAnswerUrls($answer) as $sourceUrl) {
                    if (str_contains($url, mb_strtolower($sourceUrl))) {
                        $alignmentScore += 5.0;
                    }
                }

                if ($isProcedural) {
                    $alignmentScore += $this->proceduralEvidenceAlignmentBoost($proceduralFocusTerms, $item);
                    $alignmentScore -= $this->proceduralEvidenceMismatchPenalty($question, $proceduralFocusTerms, $item);

                    foreach ($this->proceduralSignals() as $signal) {
                        if (str_contains($snippet, $signal)) {
                            $alignmentScore += 1.25;
                        }
                    }
                }

                $alignmentScore -= $this->genericEvidencePenalty($item) * ($isProcedural ? 1.5 : 1.0);
                $item['alignment_score'] = $alignmentScore + min($score, 10.0);

                return $item;
            })
            ->sortByDesc('alignment_score')
            ->values();

        $matched = $ranked
            ->filter(fn (array $item): bool => (float) ($item['alignment_score'] ?? 0.0) > 0.0)
            ->values();

        if ($matched->isNotEmpty()) {
            return $matched->take((int) config('chat.link_limit', 6))->all();
        }

        return $ranked
            ->take(min(3, max(1, count($seedEvidence))))
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $alignedEvidence
     * @return array<int, array<string, mixed>>
     */
    private function filterProceduralAlignedEvidence(string $question, array $alignedEvidence): array
    {
        if (! $this->questionRequiresProceduralSteps($question) || $alignedEvidence === []) {
            return $alignedEvidence;
        }

        $filtered = collect($alignedEvidence)
            ->filter(fn (array $item): bool => $this->supportsProceduralEvidence($question, $item))
            ->values();

        return $filtered->isNotEmpty() ? $filtered->all() : $alignedEvidence;
    }

    /**
     * @param  array<int, array{title: string, source_url: string, type: string}>  $citations
     * @param  array<int, array<string, mixed>>  $alignedEvidence
     * @param  array<int, array{source_url: string, reason: string}>  $dropped
     * @return \Illuminate\Support\Collection<int, array{title: string, source_url: string, type: string}>
     */
    private function filterProceduralCitations(string $question, Collection $citations, array $alignedEvidence, array &$dropped): Collection
    {
        if (! $this->questionRequiresProceduralSteps($question) || $citations->isEmpty()) {
            return $citations;
        }

        $supportedUrls = collect($alignedEvidence)
            ->filter(fn (array $item): bool => $this->supportsProceduralEvidence($question, $item))
            ->pluck('source_url')
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->unique()
            ->values();

        if ($supportedUrls->isEmpty()) {
            return $citations;
        }

        $filtered = $citations
            ->filter(function (array $citation) use ($supportedUrls, &$dropped): bool {
                $url = trim((string) ($citation['source_url'] ?? ''));

                if (! $supportedUrls->contains($url)) {
                    $dropped[] = [
                        'source_url' => $url,
                        'reason' => 'procedural_mismatch',
                    ];

                    return false;
                }

                return true;
            })
            ->values();

        return $filtered->isNotEmpty() ? $filtered : $citations;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function supportsProceduralEvidence(string $question, array $item): bool
    {
        if (! $this->questionRequiresProceduralSteps($question)) {
            return true;
        }

        $focusTerms = $this->proceduralFocusTerms($question);

        if ($focusTerms === []) {
            return true;
        }

        $snippet = mb_strtolower((string) ($item['snippet'] ?? ''));
        $title = mb_strtolower((string) ($item['title'] ?? ''));
        $url = mb_strtolower((string) ($item['source_url'] ?? ''));
        $context = $title.' '.$url;
        $focusInSnippet = false;
        $focusInContext = false;

        foreach ($focusTerms as $term) {
            if (str_contains($snippet, $term)) {
                $focusInSnippet = true;
            }

            if (str_contains($context, $term)) {
                $focusInContext = true;
            }
        }

        $processSignals = $this->proceduralProcessSignalCount($snippet.' '.$context);

        if ($this->genericEvidencePenalty($item) >= 4.0 && ! $focusInContext) {
            return false;
        }

        if ($focusInContext) {
            return true;
        }

        return $focusInSnippet && $processSignals >= 2;
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $item
     */
    private function proceduralEvidenceAlignmentBoost(array $focusTerms, array $item): float
    {
        if ($focusTerms === []) {
            return 0.0;
        }

        $snippet = mb_strtolower((string) ($item['snippet'] ?? ''));
        $title = mb_strtolower((string) ($item['title'] ?? ''));
        $url = mb_strtolower((string) ($item['source_url'] ?? ''));
        $context = $title.' '.$url;
        $boost = 0.0;

        foreach ($focusTerms as $term) {
            if (str_contains($snippet, $term)) {
                $boost += 4.0;
            }

            if (str_contains($context, $term)) {
                $boost += 8.0;
            }
        }

        $boost += $this->proceduralProcessSignalCount($snippet.' '.$context) * 1.5;

        return $boost;
    }

    /**
     * @param  array<int, string>  $focusTerms
     * @param  array<string, mixed>  $item
     */
    private function proceduralEvidenceMismatchPenalty(string $question, array $focusTerms, array $item): float
    {
        if (! $this->questionRequiresProceduralSteps($question) || $focusTerms === []) {
            return 0.0;
        }

        $snippet = mb_strtolower((string) ($item['snippet'] ?? ''));
        $title = mb_strtolower((string) ($item['title'] ?? ''));
        $url = mb_strtolower((string) ($item['source_url'] ?? ''));
        $context = $title.' '.$url;
        $focusInSnippet = false;
        $focusInContext = false;

        foreach ($focusTerms as $term) {
            if (str_contains($snippet, $term)) {
                $focusInSnippet = true;
            }

            if (str_contains($context, $term)) {
                $focusInContext = true;
            }
        }

        $processSignals = $this->proceduralProcessSignalCount($snippet.' '.$context);
        $penalty = 0.0;

        if ($focusInSnippet && ! $focusInContext) {
            $penalty += 18.0;
        }

        if ($processSignals === 0) {
            $penalty += 18.0;
        } elseif ($processSignals === 1) {
            $penalty += 8.0;
        }

        return $penalty;
    }

    /**
     * @return array<int, string>
     */
    private function answerAlignmentTerms(string $question, string $answer): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($question.' '.$answer)) ?: [];
        $stopwords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'what', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'are', 'is', 'was', 'were', 'can', 'could', 'should', 'would', 'will', 'have',
            'has', 'had', 'into', 'onto', 'about', 'your', 'my', 'our', 'their', 'them', 'they', 'you', 'its',
            'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'if', 'as', 'city', 'local',
            'call', 'visit', 'open', 'page', 'site', 'source', 'sources',
        ];

        return array_values(array_unique(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= 3 && ! in_array($term, $stopwords, true)
        )));
    }

    private function questionRequiresProceduralSteps(string $question): bool
    {
        return $this->isProceduralQuestion($question);
    }

    /**
     * @param  array<int, array<string, mixed>>  $seedEvidence
     * @param  array<int, array<string, mixed>>  $alignedEvidence
     */
    private function shouldConstrainProceduralAnswer(string $question, string $answer, array $seedEvidence, array $alignedEvidence): bool
    {
        if ($answer === '' || $this->isNoAnswerMessage($answer) || $this->isRefusalMessage($answer)) {
            return false;
        }

        if (! $this->isProceduralQuestion($question) && ! $this->answerLooksProcedural($answer)) {
            return false;
        }

        if (! $this->answerLooksProcedural($answer)) {
            return false;
        }

        $evidence = $alignedEvidence !== [] ? $alignedEvidence : $seedEvidence;

        if ($evidence === []) {
            return false;
        }

        return ! $this->proceduralEvidenceSupportsCompleteProcess($question, $evidence);
    }

    private function answerLooksProcedural(string $answer): bool
    {
        return preg_match('/(?:^|\n)\s*(?:\d+\.)\s+/m', $answer) === 1
            || preg_match('/\b(step|steps|first|second|third|then|next|finally|before|after)\b/i', $answer) === 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     */
    private function proceduralEvidenceSupportsCompleteProcess(string $question, array $evidence): bool
    {
        $focusTerms = $this->proceduralFocusTerms($question);
        $focusedEvidenceCount = 0;
        $actionableEvidenceCount = 0;
        $richEvidenceCount = 0;
        $conditionalEvidenceCount = 0;
        $sequencedEvidenceCount = 0;

        foreach ($evidence as $item) {
            $content = $this->normalizeSupportText(implode(' ', [
                (string) ($item['title'] ?? ''),
                (string) ($item['snippet'] ?? ''),
                (string) ($item['source_url'] ?? ''),
            ]));

            if ($content === '') {
                continue;
            }

            $focusMatches = $this->focusTermMatchCount($content, $focusTerms);
            $processSignals = $this->proceduralProcessSignalCount($content);
            $conditionalSignals = $this->conditionalSignalCount($content);
            $legalSignals = $this->legalConstraintSignalCount($content);
            $sequenceSignals = $this->sequenceSignalCount($content);

            if ($focusMatches > 0 || $focusTerms === []) {
                $focusedEvidenceCount++;
            }

            if ($conditionalSignals > 0 || $legalSignals >= 2) {
                $conditionalEvidenceCount++;
            }

            if ($sequenceSignals >= 2) {
                $sequencedEvidenceCount++;
            }

            if ($processSignals >= 3 && $focusMatches > 0 && $conditionalSignals === 0 && $legalSignals <= 1) {
                $actionableEvidenceCount++;
            }

            if ($processSignals >= 5 && $focusMatches > 0) {
                $richEvidenceCount++;
            }
        }

        if ($focusedEvidenceCount === 0) {
            return false;
        }

        if ($conditionalEvidenceCount >= max(1, (int) ceil($focusedEvidenceCount / 2))) {
            return false;
        }

        if ($actionableEvidenceCount >= 2 || $richEvidenceCount >= 2) {
            return true;
        }

        return $actionableEvidenceCount >= 1
            && $sequencedEvidenceCount >= 2
            && $focusedEvidenceCount >= 2;
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     */
    private function narrowProceduralAnswerFromEvidence(array $evidence): string
    {
        $content = collect($evidence)
            ->map(fn (array $item): string => $this->normalizeSupportText(implode(' ', [
                (string) ($item['title'] ?? ''),
                (string) ($item['snippet'] ?? ''),
                (string) ($item['source_url'] ?? ''),
            ])))
            ->filter()
            ->values();

        if ($content->isEmpty()) {
            return self::NO_ANSWER_MESSAGE;
        }

        $mentionsPermit = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'permit'));
        $mentionsReview = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'review')
            || $this->containsSignal($item, 'approval')
            || $this->containsSignal($item, 'certificate'));
        $mentionsHistoricCondition = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'historic')
            || $this->containsSignal($item, 'landmark')
            || $this->containsSignal($item, 'district')
            || $this->containsSignal($item, 'preservation'));
        $mentionsInspection = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'inspection'));
        $mentionsCleanup = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'debris')
            || $this->containsSignal($item, 'foundation')
            || $this->containsSignal($item, 'utilities')
            || $this->containsSignal($item, 'disposed'));
        $mentionsDocumentation = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'photographs')
            || $this->containsSignal($item, 'drawings')
            || $this->containsSignal($item, 'documentation'));
        $mentionsAppeal = $content->contains(fn (string $item): bool => $this->containsSignal($item, 'appeal')
            || $this->containsSignal($item, 'hearing')
            || $this->containsSignal($item, 'petition'));

        $sentences = [];

        if ($mentionsPermit || $mentionsReview) {
            $sentences[] = 'The available sources indicate that a permit or formal review may be required.';
        }

        if ($mentionsHistoricCondition && $mentionsReview) {
            $sentences[] = 'They suggest that additional review may apply in cases involving historic properties or historic districts.';
        } elseif ($mentionsReview) {
            $sentences[] = 'They mention some form of review or approval before the work can proceed.';
        }

        if ($mentionsInspection) {
            $sentences[] = $mentionsCleanup
                ? 'They also mention a final inspection after the work is complete and the site is cleared.'
                : 'They also mention a final inspection after the work is complete.';
        }

        if ($mentionsDocumentation) {
            $sentences[] = 'The sources also mention documentation requirements in some situations.';
        }

        if ($mentionsAppeal) {
            $sentences[] = 'They reference a hearing or appeal process in limited situations.';
        }

        if ($sentences === []) {
            $sentences[] = 'The available sources only provide partial legal or technical details about the process.';
        }

        $summary = collect($sentences)
            ->unique()
            ->take(2)
            ->values()
            ->all();

        $summary[] = 'The full step-by-step process is not clearly described in the available sources.';

        return implode(' ', $summary);
    }

    /**
     * @param  array<int, string>  $focusTerms
     */
    private function focusTermMatchCount(string $content, array $focusTerms): int
    {
        $matches = 0;

        foreach ($focusTerms as $term) {
            if (str_contains($content, $term)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function sequenceSignalCount(string $content): int
    {
        $matches = 0;

        foreach (['first', 'second', 'third', 'then', 'next', 'finally', 'before', 'after', 'once'] as $signal) {
            if ($this->containsSignal($content, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function conditionalSignalCount(string $content): int
    {
        $matches = 0;

        foreach (['if', 'unless', 'may', 'subject to', 'in the event', 'when', 'where'] as $signal) {
            if ($this->containsSignal($content, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function legalConstraintSignalCount(string $content): int
    {
        $matches = 0;

        foreach (['ordinance', 'code', 'section', 'subsection', 'shall', 'prohibited', 'hearing', 'board', 'council', 'resolution', 'pursuant', 'aggrieved'] as $signal) {
            if ($this->containsSignal($content, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function containsSignal(string $content, string $signal): bool
    {
        if (str_contains($signal, ' ')) {
            return str_contains($content, $signal);
        }

        return preg_match('/\b'.preg_quote($signal, '/').'\b/u', $content) === 1;
    }

    private function proceduralProcessSignalCount(string $content): int
    {
        $content = mb_strtolower($content);
        $matches = 0;

        foreach ([
            'apply',
            'application',
            'submit',
            'submitted',
            'approval',
            'approved',
            'inspection',
            'inspections',
            'required',
            'requirements',
            'document',
            'documents',
            'portal',
            'contractor',
            'review',
            'certificate',
            'office',
            'department',
            'bond',
        ] as $signal) {
            if ($this->containsSignal($content, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    /**
     * @param  array<string, mixed>|array{title: string, source_url: string, type: string}  $item
     */
    private function isGenericCitation(array $item): bool
    {
        $haystack = mb_strtolower(implode(' ', [
            (string) ($item['title'] ?? ''),
            (string) ($item['source_url'] ?? ''),
        ]));

        foreach ([
            'frequently asked questions',
            'faq',
            'quick links',
            'government',
            'city government',
            'all content',
            'boards and committees',
            '/faq',
            '/government',
        ] as $signal) {
            if (str_contains($haystack, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function genericEvidencePenalty(array $item): float
    {
        $haystack = mb_strtolower(implode(' ', [
            (string) ($item['title'] ?? ''),
            (string) ($item['snippet'] ?? ''),
            (string) ($item['source_url'] ?? ''),
        ]));
        $penalty = 0.0;

        foreach ($this->genericEvidenceSignals() as $signal) {
            if (str_contains($haystack, $signal)) {
                $penalty += 4.0;
            }
        }

        return $penalty;
    }

    private function normalizeSupportText(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/https?:\/\//', '', $value) ?? $value;
        $value = preg_replace('/^www\./', '', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @return array<int, string>
     */
    private function extractAnswerUrls(string $answer): array
    {
        preg_match_all('/https?:\/\/[^\s)>\]]+/i', $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $url): string => rtrim(trim($url), '.,;:'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function extractAnswerPhones(string $answer): array
    {
        preg_match_all($this->phonePattern(), $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $value): ?string => $this->normalizePhoneNumber($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function extractAnswerCurrencyValues(string $answer): array
    {
        preg_match_all('/\$\d[\d,]*(?:\.\d{1,2})?/', $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $value): string => mb_strtolower(trim($value)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function extractAnswerAddresses(string $answer): array
    {
        preg_match_all($this->addressPattern(), $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $value): string => $this->cleanAddress($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function requiresStrictEntityGrounding(string $question, string $answer): bool
    {
        return $this->isProceduralQuestion($question)
            || preg_match('/\b(call|contact|report|notify|reach|visit|go to|apply|submit|office|department|provider|company|agency|utility)\b/i', $question.' '.$answer) === 1;
    }

    /**
     * @return array<int, string>
     */
    private function extractReferredEntities(string $answer, City $city): array
    {
        preg_match_all(
            '/(?i:\b(?:call|contact|report(?:\s+(?:it|them))?\s+to|notify|visit|reach(?:\s+out)?\s+to|go to|through|via)\s+(?:the\s+)?)([A-Z][A-Za-z&.\'-]*(?:\s+[A-Z][A-Za-z&.\'-]*){0,3})\b/',
            $answer,
            $matches
        );

        $ignored = collect([
            $city->name,
            'Monday',
            'Tuesday',
            'Wednesday',
            'Thursday',
            'Friday',
            'Saturday',
            'Sunday',
            'Today',
            'Tomorrow',
            'Source',
        ])->map(fn (string $value): string => $this->normalizeSupportText($value))->all();

        return collect($matches[1] ?? [])
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->reject(fn (string $value): bool => in_array($this->normalizeSupportText($value), $ignored, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *     start_at: Carbon,
     *     end_at: Carbon,
     *     label: string,
     *     is_explicit: bool,
     *     parse_confidence: float
     * }|null  $window
     */
    private function noEventsFoundMessage(City $city, ?array $window): string
    {
        $label = is_array($window) && is_string($window['label'] ?? null)
            ? $window['label']
            : 'that time period';

        if (! (bool) config('chat.events.no_results_suggest_alternatives', true)) {
            return "I could not find any events in {$city->name} for {$label}.";
        }

        return "I could not find any events in {$city->name} for {$label}. Try asking about the next 7 days or next weekend.";
    }

    /**
     * @return array<int, string>
     */
    private function temporalContextLines(City $city): array
    {
        $timezone = $city->timezone ?: config('app.timezone', 'UTC');
        $localNow = Carbon::now($timezone);

        return [
            'City timezone: '.$timezone,
            'Current local datetime: '.$localNow->toIso8601String(),
            'Current local date: '.$localNow->toDateString().' ('.$localNow->format('l').')',
            'Interpret relative date phrases (today, yesterday, tomorrow, this week, last week, next week, this month) using this local time unless the user provides explicit dates.',
            'When useful, include concrete calendar dates in the answer instead of only relative phrasing.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function eventWebAllowedDomains(City $city): array
    {
        $mode = (string) config('chat.events.web_fallback.allowed_domains_mode', 'city_event_sources_merged');
        $cityDomains = $this->eventSourceDomains($city);
        $globalDomains = collect(config('chat.events.web_fallback.allowed_domains', []))
            ->filter(fn ($domain): bool => is_string($domain) && trim($domain) !== '')
            ->map(fn (string $domain): string => $this->normalizeDomain($domain))
            ->filter()
            ->values();

        return match ($mode) {
            'city_event_sources' => $cityDomains->all(),
            'global' => $globalDomains->unique()->values()->all(),
            default => $cityDomains->merge($globalDomains)->unique()->values()->all(),
        };
    }

    /**
     * @return Collection<int, string>
     */
    private function eventSourceDomains(City $city): Collection
    {
        if (! $city->id) {
            return collect();
        }

        return EventSource::query()
            ->where('city_id', $city->id)
            ->where('is_active', true)
            ->pluck('source_url')
            ->filter(fn ($url): bool => is_string($url) && trim($url) !== '')
            ->map(fn (string $url): string => $this->normalizeDomain($url))
            ->filter()
            ->values();
    }

    private function normalizeDomain(string $value): string
    {
        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || trim($host) === '') {
            $host = $value;
        }

        $host = mb_strtolower(trim($host));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return trim($host);
    }

    private function streamProvider(): string
    {
        $providers = config('chat.provider_chain');

        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if (is_string($provider) && trim($provider) !== '') {
                    return $provider;
                }
            }
        }

        return (string) config('chat.provider', 'openai');
    }

    /**
     * @param  array<string, mixed>  $eventContext
     * @param  array<string, mixed>  $proceduralContext
     */
    private function chatModelForQuestion(string $question, array $eventContext = [], array $proceduralContext = []): string
    {
        return (string) config('chat.model', config('enrichment.model', 'gpt-4o-mini'));
    }

    /**
     * @return array<string, string|null>
     */
    private function providerPreference(
        string $chainConfigKey,
        string $fallbackProviderConfigKey,
        string $model,
    ): array {
        $providers = config($chainConfigKey);

        if (! is_array($providers) || $providers === []) {
            return [
                (string) config($fallbackProviderConfigKey, 'openai') => $model,
            ];
        }

        $resolved = [];

        foreach (array_values($providers) as $index => $provider) {
            if (! is_string($provider) || trim($provider) === '') {
                continue;
            }

            $resolved[$provider] = $index === 0 ? $model : null;
        }

        if ($resolved === []) {
            return [
                (string) config($fallbackProviderConfigKey, 'openai') => $model,
            ];
        }

        return $resolved;
    }
}
