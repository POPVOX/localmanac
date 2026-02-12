<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Services\Analysis\Agents\LlmScoringAgent;
use RuntimeException;

class LlmScorer
{
    public const PROMPT_VERSION = 'crf_v1_prompt_001';

    public function __construct(
        private readonly LlmScoringAgent $llmScoringAgent,
    ) {}

    /**
     * @return array{
     *     dimensions: array<string, float>,
     *     justifications: array<string, string>,
     *     opportunities: array<int, array<string, mixed>>,
     *     confidence: float,
     *     model: string
     * }
     */
    public function score(Article $article): array
    {
        if (! config('analysis.llm.enabled', false)) {
            throw new RuntimeException('LLM scoring is not enabled.');
        }

        $article->loadMissing(['body', 'city', 'scraper.organization']);

        $text = trim((string) ($article->body?->cleaned_text ?? ''));

        if ($text === '') {
            throw new RuntimeException('LLM scoring text is empty.');
        }

        $response = $this->llmScoringAgent->prompt(
            $this->prompt($article, $text),
            provider: $this->providerPreference(
                chainConfigKey: 'analysis.llm.provider_chain',
                fallbackProviderConfigKey: 'analysis.llm.provider',
                model: (string) config('analysis.llm.model', config('enrichment.model', 'gpt-4o-mini')),
            ),
            timeout: (int) config('analysis.llm.timeout', 120),
        );

        $structured = is_array($response->structured ?? null)
            ? $response->structured
            : [];

        $dimensions = $this->normalizeDimensions($structured['dimensions'] ?? []);
        $justifications = $this->normalizeJustifications($structured['justifications'] ?? []);

        $opportunities = collect($structured['opportunities'] ?? [])
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item): array => [
                'kind' => (string) ($item['kind'] ?? 'other'),
                'title' => $this->normalizeNullableString($item['title'] ?? null),
                'starts_at' => $this->normalizeNullableString($item['starts_at'] ?? null),
                'ends_at' => $this->normalizeNullableString($item['ends_at'] ?? null),
                'location' => $this->normalizeNullableString($item['location'] ?? null),
                'url' => $this->normalizeNullableString($item['url'] ?? null),
                'notes' => $this->normalizeNullableString($item['notes'] ?? null),
                'confidence' => $this->clampConfidence($item['confidence'] ?? null),
            ])
            ->values()
            ->all();

        return [
            'dimensions' => $dimensions,
            'justifications' => $justifications,
            'opportunities' => $opportunities,
            'confidence' => $this->clampConfidence($structured['confidence'] ?? null),
            'model' => (string) ($response->meta->model ?? ''),
        ];
    }

    private function prompt(Article $article, string $text): string
    {
        $city = $article->city?->name ?? 'Unknown';
        $organization = $article->scraper?->organization?->name ?? 'Unknown';
        $title = $article->title ?? 'Untitled';

        $dimensionList = implode(', ', ScoreDimensions::keys());

        return <<<PROMPT
You are scoring civic relevance for a local news/civic article.
Use only the article text. Be conservative and evidence-driven.

Return JSON with:
- dimensions: object with keys {$dimensionList}, each 0.0 to 1.0
- justifications: object with short evidence-based strings for each dimension key
- opportunities: list of objects with keys kind,title,starts_at,ends_at,location,url,notes,confidence
- confidence: overall score from 0.0 to 1.0

Rules:
- If evidence is weak, use lower scores.
- Do not invent dates, links, or locations.
- Keep justifications concise.
- Opportunity kind should be one of: meeting,public_comment,deadline,application,other

Context:
City: {$city}
Organization: {$organization}
Title: {$title}

Article text:
{$text}
PROMPT;
    }

    /**
     * @return array<string, float>
     */
    private function normalizeDimensions(mixed $dimensions): array
    {
        $dimensions = is_array($dimensions) ? $dimensions : [];

        $result = [];

        foreach (ScoreDimensions::keys() as $key) {
            $result[$key] = $this->clampConfidence($dimensions[$key] ?? null);
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function normalizeJustifications(mixed $justifications): array
    {
        $justifications = is_array($justifications) ? $justifications : [];

        $result = [];

        foreach (ScoreDimensions::keys() as $key) {
            $value = $justifications[$key] ?? '';
            $result[$key] = is_string($value) ? trim($value) : '';
        }

        return $result;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function clampConfidence(mixed $value): float
    {
        if (! is_numeric($value)) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) $value));
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
