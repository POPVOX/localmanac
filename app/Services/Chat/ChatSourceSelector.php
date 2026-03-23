<?php

namespace App\Services\Chat;

use App\Models\ChatSource;
use Illuminate\Support\Collection;
use Throwable;

class ChatSourceSelector
{
    /**
     * @return Collection<int, ChatSource>
     */
    public function select(int $cityId, string $question, ?int $limit = null): Collection
    {
        $limit = $limit ?? (int) config('chat.max_sources', 12);
        $question = trim($question);
        $isProceduralQuestion = $this->isProceduralQuestion($question);

        $sources = collect();

        if ($question !== '') {
            try {
                $sources = ChatSource::search($question)
                    ->where('city_id', $cityId)
                    ->where('is_active', true)
                    ->take($limit)
                    ->get();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $fallback = collect();

        if ($sources->count() < $limit || $isProceduralQuestion) {
            $fallback = ChatSource::query()
                ->where('city_id', $cityId)
                ->where('is_active', true)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->take(max($limit * 2, $limit))
                ->get();
        }

        $sources = $sources
            ->merge($fallback)
            ->unique('id')
            ->values();

        if ($isProceduralQuestion) {
            return $this->rankProceduralSources($sources, $question, $limit);
        }

        return $sources
            ->take($limit)
            ->values();
    }

    /**
     * @param  Collection<int, ChatSource>  $sources
     * @return Collection<int, ChatSource>
     */
    private function rankProceduralSources(Collection $sources, string $question, int $limit): Collection
    {
        $terms = $this->keywordTerms($question);

        if ($terms === [] || $sources->isEmpty()) {
            return $sources->take($limit)->values();
        }

        $ranked = $sources
            ->values()
            ->map(function (ChatSource $source, int $index) use ($terms): array {
                $overlap = $this->sourceOverlap($source, $terms);
                $proceduralSignals = $this->sourceProceduralSignals($source);
                $genericPenalty = $this->genericSourcePenalty($source, $overlap);

                $score = 0.0;
                $score += ($overlap['tags'] * 12.0)
                    + ($overlap['name'] * 10.0)
                    + ($overlap['description'] * 6.0)
                    + ($overlap['url'] * 4.0);
                $score += $proceduralSignals * 2.5;
                $score += min((int) $source->priority, 20) * 0.35;
                $score -= $genericPenalty;

                return [
                    'source' => $source,
                    'score' => $score,
                    'overlap' => array_sum($overlap),
                    'procedural_signals' => $proceduralSignals,
                    'fallback_index' => $index,
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['score'], $right['overlap'], $right['procedural_signals'], $right['source']->priority, -$right['fallback_index']]
                    <=> [$left['score'], $left['overlap'], $left['procedural_signals'], $left['source']->priority, -$left['fallback_index']];
            })
            ->values();

        $matched = $ranked
            ->filter(fn (array $entry): bool => $entry['overlap'] > 0 || $entry['procedural_signals'] > 0)
            ->values();

        if ($matched->count() >= $limit) {
            return $matched
                ->take($limit)
                ->pluck('source')
                ->values();
        }

        return $matched
            ->concat(
                $ranked->reject(fn (array $entry): bool => $matched->contains(fn (array $matchedEntry): bool => $matchedEntry['source']->is($entry['source'])))
            )
            ->take($limit)
            ->pluck('source')
            ->values();
    }

    /**
     * @param  array<int, string>  $terms
     * @return array{name: int, tags: int, description: int, url: int}
     */
    private function sourceOverlap(ChatSource $source, array $terms): array
    {
        $name = mb_strtolower($source->name);
        $description = mb_strtolower((string) ($source->description ?? ''));
        $url = mb_strtolower($source->source_url);
        $tags = collect($source->tags ?? [])
            ->filter(fn ($tag): bool => is_string($tag))
            ->map(fn (string $tag): string => mb_strtolower($tag))
            ->all();

        $overlap = [
            'name' => 0,
            'tags' => 0,
            'description' => 0,
            'url' => 0,
        ];

        foreach ($terms as $term) {
            if (str_contains($name, $term)) {
                $overlap['name']++;
            }

            if (str_contains($description, $term)) {
                $overlap['description']++;
            }

            if (str_contains($url, $term)) {
                $overlap['url']++;
            }

            foreach ($tags as $tag) {
                if (str_contains($tag, $term) || str_contains($term, $tag)) {
                    $overlap['tags']++;

                    break;
                }
            }
        }

        return $overlap;
    }

    private function sourceProceduralSignals(ChatSource $source): int
    {
        $haystack = mb_strtolower(implode(' ', [
            $source->name,
            (string) ($source->description ?? ''),
            $source->source_url,
            implode(' ', array_filter($source->tags ?? [], fn ($tag): bool => is_string($tag))),
        ]));

        $matches = 0;

        foreach ($this->proceduralSignals() as $signal) {
            if (str_contains($haystack, $signal)) {
                $matches++;
            }
        }

        return $matches;
    }

    private function genericSourcePenalty(ChatSource $source, array $overlap): float
    {
        $haystack = mb_strtolower(implode(' ', [
            $source->name,
            (string) ($source->description ?? ''),
            $source->source_url,
            implode(' ', array_filter($source->tags ?? [], fn ($tag): bool => is_string($tag))),
        ]));

        $hasExplicitMatch = array_sum($overlap) > 0;
        $penalty = 0.0;

        foreach ([
            'faq',
            'government',
            'city government',
            'quick links',
            'news flash',
            'boards and committees',
            'animals',
            'schools',
            'trash',
            'recycling',
        ] as $genericSignal) {
            if (str_contains($haystack, $genericSignal)) {
                $penalty += $hasExplicitMatch ? 2.0 : 8.0;
            }
        }

        return $penalty;
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

        foreach ($this->proceduralSignals() as $signal) {
            if (str_contains($question, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function keywordTerms(string $question): array
    {
        $terms = preg_split('/\W+/u', mb_strtolower($question)) ?: [];
        $stopwords = [
            'the', 'and', 'for', 'with', 'that', 'this', 'from', 'what', 'when', 'where', 'which', 'who', 'whom',
            'does', 'do', 'did', 'are', 'is', 'was', 'were', 'can', 'could', 'should', 'would', 'will', 'have',
            'has', 'had', 'into', 'onto', 'about', 'your', 'my', 'our', 'their', 'them', 'they', 'you', 'its',
            'a', 'an', 'of', 'to', 'in', 'on', 'at', 'by', 'or', 'if', 'as', 'i', 'get',
        ];

        return array_values(array_unique(array_filter(
            $terms,
            fn (string $term): bool => mb_strlen($term) >= 3 && ! in_array($term, $stopwords, true)
        )));
    }

    /**
     * @return array<int, string>
     */
    private function proceduralSignals(): array
    {
        return [
            'permit',
            'permits',
            'license',
            'licenses',
            'apply',
            'application',
            'demolition',
            'inspection',
            'inspections',
            'contractor',
            'contractors',
            'historic',
            'approval',
            'submit',
            'review',
            'portal',
            'fee',
            'fees',
            'building',
        ];
    }
}
