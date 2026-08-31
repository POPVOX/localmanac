<?php

namespace App\Services\Chat;

use Illuminate\Support\Collection;

class EvidenceSelector
{
    /**
     * Apply a source-diversity pass and a bounded context budget while retaining
     * the incoming relevance order.
     *
     * @param  Collection<int, array<string, mixed>>  $evidence
     * @return Collection<int, array<string, mixed>>
     */
    public function select(Collection $evidence, int $limit): Collection
    {
        $limit = max(1, $limit);
        $maxPerSource = max(1, (int) config('chat.retrieval_max_evidence_per_source', 3));
        $tokenBudget = max(0, (int) config('chat.retrieval_context_token_budget', 5000));
        $selected = collect();
        $deferred = collect();
        $sourceCounts = [];
        $tokensUsed = 0;

        foreach ($evidence->values() as $item) {
            $source = $this->sourceIdentity($item);

            if (($sourceCounts[$source] ?? 0) >= $maxPerSource) {
                $deferred->push($item);

                continue;
            }

            if (! $this->appendWithinBudget($selected, $item, $limit, $tokenBudget, $tokensUsed)) {
                continue;
            }

            $sourceCounts[$source] = ($sourceCounts[$source] ?? 0) + 1;
        }

        foreach ($deferred as $item) {
            if ($selected->count() >= $limit) {
                break;
            }

            $this->appendWithinBudget($selected, $item, $limit, $tokenBudget, $tokensUsed);
        }

        return $selected->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $selected
     * @param  array<string, mixed>  $item
     */
    private function appendWithinBudget(
        Collection $selected,
        array $item,
        int $limit,
        int $tokenBudget,
        int &$tokensUsed,
    ): bool {
        if ($selected->count() >= $limit) {
            return false;
        }

        $estimatedTokens = $this->estimatedTokens((string) ($item['snippet'] ?? ''));

        if ($tokenBudget > 0 && $tokensUsed + $estimatedTokens > $tokenBudget) {
            if ($selected->isNotEmpty()) {
                return false;
            }

            $item['snippet'] = mb_substr((string) ($item['snippet'] ?? ''), 0, $tokenBudget * 4);
            $estimatedTokens = $this->estimatedTokens((string) $item['snippet']);
        }

        $selected->push($item);
        $tokensUsed += $estimatedTokens;

        return true;
    }

    /** @param array<string, mixed> $item */
    private function sourceIdentity(array $item): string
    {
        $url = mb_strtolower(trim((string) ($item['source_url'] ?? '')));

        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);

            if (is_string($host) && $host !== '') {
                return 'host:'.$host;
            }
        }

        return $url !== '' ? 'url:'.$url : 'item:'.(string) ($item['id'] ?? spl_object_id((object) $item));
    }

    private function estimatedTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }
}
