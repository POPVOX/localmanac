<?php

namespace App\Services\Chat;

use App\Models\City;
use App\Models\User;
use Illuminate\Support\Collection;
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
    public function answer(string $question, ?int $cityId = null, ?string $citySlug = null): array
    {
        $question = trim($question);
        $city = $this->resolveCity($cityId, $citySlug);
        $sources = $this->selector->select($city->id, $question);

        if ($sources->isEmpty()) {
            return $this->fallbackResponse($city, $sources);
        }

        try {
            $answerPayload = $this->synthesizer->synthesize($question, $city, $sources);
        } catch (\Throwable) {
            return $this->fallbackResponse($city, $sources);
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);

        if ($citations === [] && $answer !== '') {
            $citations = $this->fallbackCitations($sources);
        }

        if ($citations === [] || $answer === '') {
            return $this->fallbackResponse($city, $sources);
        }

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
        $sources = $this->selector->select($city->id, $question);

        if ($sources->isEmpty()) {
            $fallback = $this->fallbackResponse($city, $sources);

            return array_merge($fallback, ['conversation_id' => $conversationId]);
        }

        try {
            $answerPayload = $this->synthesizer->synthesizeStreaming(
                question: $question,
                city: $city,
                sources: $sources,
                user: $user,
                conversationId: $conversationId,
                onDelta: $onDelta,
            );
        } catch (\Throwable) {
            $fallback = $this->fallbackResponse($city, $sources);

            return array_merge($fallback, ['conversation_id' => $conversationId]);
        }

        $answer = trim((string) ($answerPayload['answer'] ?? ''));
        $citations = $this->normalizeCitations($answerPayload['citations'] ?? []);

        if ($citations === [] && $answer !== '') {
            $citations = $this->fallbackCitations($sources);
        }

        if ($citations === [] || $answer === '') {
            $fallback = $this->fallbackResponse($city, $sources);

            return array_merge($fallback, [
                'conversation_id' => is_string($answerPayload['conversation_id'] ?? null)
                    ? $answerPayload['conversation_id']
                    : $conversationId,
            ]);
        }

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
    private function fallbackResponse(City $city, Collection $sources): array
    {
        $citations = $this->fallbackCitations($sources);

        return [
            'answer' => __('I could not find the answer in the sources I checked. Try a different wording or a more specific question.'),
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
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function fallbackCitations(Collection $sources): array
    {
        return $sources
            ->take(3)
            ->map(function ($source): array {
                $url = trim((string) ($source->source_url ?? ''));

                return [
                    'title' => trim((string) ($source->name ?? 'Source')) ?: 'Source',
                    'source_url' => $url,
                    'type' => $this->inferCitationType($url),
                ];
            })
            ->filter(fn (array $citation): bool => $citation['source_url'] !== '')
            ->unique('source_url')
            ->values()
            ->all();
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
}
