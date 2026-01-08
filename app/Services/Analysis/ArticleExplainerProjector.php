<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Models\ArticleExplainer;
use Illuminate\Support\Str;

class ArticleExplainerProjector
{
    public function projectForArticle(Article $article, ?array $payload = null): void
    {
        $article->loadMissing(['analysis', 'city']);

        $extraction = $this->extractExplainer($article, $payload);
        $explainer = $extraction['explainer'];

        ArticleExplainer::updateOrCreate(
            ['article_id' => $article->id],
            [
                'city_id' => $article->city_id,
                'whats_happening' => $explainer['whats_happening'],
                'why_it_matters' => $explainer['why_it_matters'],
                'key_details' => $explainer['key_details'],
                'what_to_watch' => $explainer['what_to_watch'],
                'evidence_json' => $explainer['evidence'],
                'source' => 'analysis_llm',
                'source_payload' => $extraction['source_payload'],
            ]
        );
    }

    /**
     * @return array{
     *   explainer: array{
     *     whats_happening: string|null,
     *     why_it_matters: string|null,
     *     key_details: array<int, string|array{label: string, value: string}>|null,
     *     what_to_watch: array<int, string|array{label: string, value: string}>|null,
     *     evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     *   },
     *   source_payload: array<string, mixed>|null
     * }
     */
    private function extractExplainer(Article $article, ?array $payload): array
    {
        if (is_array($payload) && array_key_exists('explainer', $payload)) {
            $sourcePayload = is_array($payload['explainer'] ?? null) ? $payload['explainer'] : null;
            $normalized = $this->normalizeExplainer($sourcePayload);

            return [
                'explainer' => $normalized,
                'source_payload' => $sourcePayload,
            ];
        }

        $analysis = $article->analysis;

        if (! $analysis) {
            return [
                'explainer' => $this->emptyExplainer(),
                'source_payload' => null,
            ];
        }

        $finalScores = $this->normalizePayload($analysis->final_scores ?? null);
        $finalExplainer = is_array($finalScores['explainer'] ?? null) ? $finalScores['explainer'] : null;
        $normalized = $this->normalizeExplainer($finalExplainer);

        if ($this->hasExplainerContent($normalized)) {
            return [
                'explainer' => $normalized,
                'source_payload' => $finalExplainer,
            ];
        }

        $llmScores = $this->normalizePayload($analysis->llm_scores ?? null);
        $llmExplainer = is_array($llmScores['explainer'] ?? null) ? $llmScores['explainer'] : null;

        return [
            'explainer' => $this->normalizeExplainer($llmExplainer),
            'source_payload' => $llmExplainer,
        ];
    }

    /**
     * @return array{
     *   whats_happening: string|null,
     *   why_it_matters: string|null,
     *   key_details: array<int, string|array{label: string, value: string}>|null,
     *   what_to_watch: array<int, string|array{label: string, value: string}>|null,
     *   evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     * }
     */
    private function normalizeExplainer(?array $explainer): array
    {
        if (! is_array($explainer)) {
            return $this->emptyExplainer();
        }

        return [
            'whats_happening' => $this->normalizeText($explainer['whats_happening'] ?? null),
            'why_it_matters' => $this->normalizeText($explainer['why_it_matters'] ?? null),
            'key_details' => $this->normalizeBullets($explainer['key_details'] ?? null),
            'what_to_watch' => $this->normalizeBullets($explainer['what_to_watch'] ?? null),
            'evidence' => $this->normalizeEvidenceMap($explainer['evidence'] ?? null),
        ];
    }

    /**
     * @return array{
     *   whats_happening: string|null,
     *   why_it_matters: string|null,
     *   key_details: array<int, string|array{label: string, value: string}>|null,
     *   what_to_watch: array<int, string|array{label: string, value: string}>|null,
     *   evidence: array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     * }
     */
    private function emptyExplainer(): array
    {
        return [
            'whats_happening' => null,
            'why_it_matters' => null,
            'key_details' => null,
            'what_to_watch' => null,
            'evidence' => null,
        ];
    }

    /**
     * @return array<int, string|array{label: string, value: string}>|null
     */
    private function normalizeBullets(mixed $items): ?array
    {
        if (! is_array($items)) {
            return null;
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $text = $this->normalizeText($item);

                if ($text !== null) {
                    $normalized[] = $text;
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $label = $this->normalizeText($item['label'] ?? null);
            $value = $this->normalizeText($item['value'] ?? null);

            if ($label !== null && $value !== null) {
                $normalized[] = ['label' => $label, 'value' => $value];

                continue;
            }

            $text = $this->normalizeText($item['text'] ?? null) ?? $label ?? $value;

            if ($text !== null) {
                $normalized[] = $text;
            }
        }

        if ($normalized === []) {
            return null;
        }

        return array_slice($normalized, 0, 5);
    }

    /**
     * @return array<string, array<int, array{quote: string, start?: int, end?: int}>>|null
     */
    private function normalizeEvidenceMap(mixed $evidence): ?array
    {
        if (! is_array($evidence)) {
            return null;
        }

        $normalized = [];

        foreach ($evidence as $section => $items) {
            if (! is_string($section) || ! is_array($items)) {
                continue;
            }

            $list = $this->normalizeEvidenceList($items);

            if ($list !== []) {
                $normalized[$section] = $list;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{quote: string, start?: int, end?: int}>
     */
    private function normalizeEvidenceList(array $items): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quote = $this->stringValue($item['quote'] ?? null);

            if ($quote === null) {
                continue;
            }

            $entry = ['quote' => $quote];

            $start = $this->numberValue($item['start'] ?? null);
            if ($start !== null) {
                $entry['start'] = $start;
            }

            $end = $this->numberValue($item['end'] ?? null);
            if ($end !== null) {
                $entry['end'] = $end;
            }

            $normalized[] = $entry;
        }

        return $normalized;
    }

    private function hasExplainerContent(array $explainer): bool
    {
        return $explainer['whats_happening'] !== null
            || $explainer['why_it_matters'] !== null
            || $explainer['key_details'] !== null
            || $explainer['what_to_watch'] !== null
            || $explainer['evidence'] !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload)) {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = Str::squish($value);

        return $value === '' ? null : $value;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function numberValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }
}
