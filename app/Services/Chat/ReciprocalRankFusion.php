<?php

namespace App\Services\Chat;

class ReciprocalRankFusion
{
    /**
     * Fuse independently ranked candidate lists without comparing their raw scores.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $rankedLists
     * @return array<int, array<string, mixed>>
     */
    public function fuse(array $rankedLists, string $identityKey): array
    {
        $k = max(1, (int) config('chat.retrieval_rrf_k', 60));
        $maxCandidates = max(1, min(100, (int) config('chat.retrieval_candidate_limit', 40)));
        $pool = [];

        foreach (array_slice($rankedLists, 0, 12, true) as $source => $rows) {
            foreach (array_values($rows) as $rank => $row) {
                $identity = trim((string) ($row[$identityKey] ?? ''));

                if ($identity === '') {
                    continue;
                }

                if (! isset($pool[$identity])) {
                    $pool[$identity] = [
                        'identity' => $identity,
                        'row' => $row,
                        'score' => 0.0,
                        'best_rank' => $rank,
                        'contributions' => [],
                    ];
                }

                $contribution = 1 / ($k + $rank + 1);
                $pool[$identity]['score'] += $contribution;
                $pool[$identity]['best_rank'] = min($pool[$identity]['best_rank'], $rank);
                $pool[$identity]['contributions'][] = [
                    'source' => (string) $source,
                    'rank' => $rank,
                ];
            }
        }

        $fused = array_values($pool);

        usort($fused, fn (array $left, array $right): int => [
            -$left['score'],
            $left['best_rank'],
            $left['identity'],
        ] <=> [
            -$right['score'],
            $right['best_rank'],
            $right['identity'],
        ]);

        return array_map(function (array $entry): array {
            $row = $entry['row'];
            $row['rrf_score'] = round($entry['score'], 8);
            $row['rrf_contributions'] = $entry['contributions'];
            $row['score'] = max(1, (int) round($entry['score'] * 1000));

            return $row;
        }, array_slice($fused, 0, $maxCandidates));
    }
}
