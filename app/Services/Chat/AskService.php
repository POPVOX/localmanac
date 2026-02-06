<?php

namespace App\Services\Chat;

use App\Models\City;
use Illuminate\Support\Collection;
use RuntimeException;

class AskService
{
    public function __construct(
        private readonly ChatSourceSelector $selector,
        private readonly ChatSourceRetriever $retriever,
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
        $fetch = $this->retriever->retrieve($sources, $question);
        $evidence = $this->filterEvidence($fetch['evidence']);
        $meta = $fetch['meta'];

        if ($evidence === []) {
            return $this->fallbackResponse($city, $sources, $meta, $evidence);
        }

        try {
            $answerPayload = $this->synthesizer->synthesize($question, $city, $evidence);
        } catch (\Throwable) {
            return $this->fallbackResponse($city, $sources, $meta, $evidence);
        }
        $answer = (string) ($answerPayload['answer'] ?? '');
        $citations = $this->mapCitations($answerPayload['citation_ids'], $evidence);

        if ($citations === [] && trim($answer) !== '' && $evidence !== []) {
            $citations = $this->fallbackCitations($evidence);
        }

        if ($citations === [] || trim($answer) === '') {
            return $this->fallbackResponse($city, $sources, $meta, $evidence);
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
                'pages_fetched' => $meta['pages_fetched'] ?? 0,
                'cache_hits' => $meta['cache_hits'] ?? 0,
            ],
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

    /**
     * @param  array<int, string>  $citationIds
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function mapCitations(array $citationIds, array $evidence): array
    {
        $byId = collect($evidence)->keyBy('id');

        return collect($citationIds)
            ->map(fn (string $id) => $byId->get($id))
            ->filter()
            ->map(fn (array $item) => [
                'title' => (string) ($item['title'] ?? 'Source'),
                'source_url' => (string) ($item['source_url'] ?? ''),
                'type' => (string) ($item['type'] ?? 'html'),
            ])
            ->unique('source_url')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, \App\Models\ChatSource>  $sources
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array{
     *     answer: string,
     *     citations: array<int, array{title: string, source_url: string, type: string}>,
     *     city: array{id: int, name: string, slug: string},
     *     meta: array{sources_used: int, pages_fetched: int, cache_hits: int}
     * }
     */
    private function fallbackResponse(City $city, Collection $sources, array $meta, array $evidence): array
    {
        $citations = $this->fallbackCitations($evidence);

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
                'pages_fetched' => $meta['pages_fetched'] ?? 0,
                'cache_hits' => $meta['cache_hits'] ?? 0,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<int, array<string, mixed>>
     */
    private function filterEvidence(array $evidence): array
    {
        $minScore = (int) config('chat.min_evidence_score_per_page', 2);

        if ($minScore <= 0) {
            return $evidence;
        }

        return array_values(array_filter(
            $evidence,
            fn (array $item) => (int) ($item['score'] ?? 0) >= $minScore
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $evidence
     * @return array<int, array{title: string, source_url: string, type: string}>
     */
    private function fallbackCitations(array $evidence): array
    {
        return collect($this->filterEvidence($evidence))
            ->take(3)
            ->map(fn (array $item) => [
                'title' => (string) ($item['title'] ?? 'Source'),
                'source_url' => (string) ($item['source_url'] ?? ''),
                'type' => (string) ($item['type'] ?? 'html'),
            ])
            ->unique('source_url')
            ->values()
            ->all();
    }
}
