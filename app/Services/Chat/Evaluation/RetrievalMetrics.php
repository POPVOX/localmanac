<?php

namespace App\Services\Chat\Evaluation;

class RetrievalMetrics
{
    /**
     * @param  array<int, string>  $retrieved
     * @param  array<int, string>  $required
     * @param  array<int, string>  $anyOf
     * @param  array<int, string>  $excluded
     * @return array<string, bool|float|int>
     */
    public function evaluate(
        array $retrieved,
        array $required = [],
        array $anyOf = [],
        array $excluded = [],
        bool $expectNoSource = false,
        int $k = 10,
    ): array {
        $k = max(1, $k);
        $retrieved = array_slice($this->normalizeList($retrieved), 0, $k);
        $required = $this->normalizeList($required);
        $anyOf = $this->normalizeList($anyOf);
        $excluded = $this->normalizeList($excluded);
        $requiredFound = count(array_intersect($required, $retrieved));
        $anyOfFound = $anyOf === [] ? 0 : (int) (array_intersect($anyOf, $retrieved) !== []);
        $recallDenominator = count($required) + ($anyOf === [] ? 0 : 1);
        $relevant = array_values(array_unique(array_merge($required, $anyOf)));
        $relevantRetrieved = count(array_intersect($relevant, $retrieved));
        $excludedHits = count(array_intersect($excluded, $retrieved));
        $noSourceCorrect = ! $expectNoSource || $retrieved === [];
        $requiredComplete = $requiredFound === count($required);
        $anyOfComplete = $anyOf === [] || $anyOfFound === 1;

        return [
            'pass' => $requiredComplete && $anyOfComplete && $excludedHits === 0 && $noSourceCorrect,
            'recall_at_k' => $recallDenominator === 0
                ? (float) ($expectNoSource ? (int) $noSourceCorrect : 1)
                : (float) (($requiredFound + $anyOfFound) / $recallDenominator),
            'precision_at_k' => $retrieved === []
                ? (float) ($expectNoSource ? (int) $noSourceCorrect : 0)
                : (float) ($relevantRetrieved / count($retrieved)),
            'reciprocal_rank' => $this->reciprocalRank($retrieved, $relevant),
            'ndcg_at_k' => $this->ndcg($retrieved, $relevant),
            'excluded_hits' => $excludedHits,
            'retrieved_count' => count($retrieved),
            'no_source_correct' => $noSourceCorrect,
        ];
    }

    /** @param array<int, string> $values */
    private function normalizeList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $value): string => $this->normalizeIdentity($value),
            $values,
        ))));
    }

    private function normalizeIdentity(string $value): string
    {
        $value = trim($value);

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            $scheme = mb_strtolower($parts['scheme'] ?? 'https');
            $host = mb_strtolower($parts['host'] ?? '');
            $path = rtrim($parts['path'] ?? '', '/');
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

            return $host === '' ? $value : $scheme.'://'.$host.$path.$query;
        }

        return mb_strtolower($value);
    }

    /**
     * @param  array<int, string>  $retrieved
     * @param  array<int, string>  $relevant
     */
    private function reciprocalRank(array $retrieved, array $relevant): float
    {
        foreach ($retrieved as $rank => $identity) {
            if (in_array($identity, $relevant, true)) {
                return 1 / ($rank + 1);
            }
        }

        return 0.0;
    }

    /**
     * @param  array<int, string>  $retrieved
     * @param  array<int, string>  $relevant
     */
    private function ndcg(array $retrieved, array $relevant): float
    {
        if ($relevant === []) {
            return $retrieved === [] ? 1.0 : 0.0;
        }

        $dcg = 0.0;

        foreach ($retrieved as $rank => $identity) {
            if (in_array($identity, $relevant, true)) {
                $dcg += 1 / log($rank + 2, 2);
            }
        }

        $idealCount = min(count($relevant), count($retrieved));
        $ideal = 0.0;

        for ($rank = 0; $rank < $idealCount; $rank++) {
            $ideal += 1 / log($rank + 2, 2);
        }

        return $ideal > 0 ? $dcg / $ideal : 0.0;
    }
}
