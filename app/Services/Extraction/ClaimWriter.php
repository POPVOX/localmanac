<?php

namespace App\Services\Extraction;

use App\Models\Article;
use App\Models\Claim;
use App\Support\Claims\ClaimTypes;
use Illuminate\Support\Facades\DB;

class ClaimWriter
{
    /**
     * @param  array{
     *     people?: array<int, array{name?: string, role?: string|null, confidence?: float, evidence?: array<int, array{quote?: string, start?: int, end?: int}>>>,
     *     organizations?: array<int, array{name?: string, type_guess?: string, confidence?: float, evidence?: array<int, array{quote?: string, start?: int, end?: int}>>>,
     *     locations?: array<int, array{name?: string, address?: string|null, confidence?: float, evidence?: array<int, array{quote?: string, start?: int, end?: int}>>>,
     *     keywords?: array<int, array{keyword?: string, confidence?: float, evidence?: array<int, array{quote?: string, start?: int, end?: int}>>>,
     *     issue_areas?: array<int, array{slug?: string, confidence?: float, evidence?: array<int, array{quote?: string, start?: int, end?: int}>>>,
     *     confidence?: float
     * }  $payload
     */
    public function write(
        Article $article,
        array $payload,
        string $model,
        string $promptVersion,
        string $source = 'llm'
    ): void {
        DB::transaction(function () use ($article, $payload, $model, $promptVersion, $source) {
            Claim::query()
                ->where('article_id', $article->id)
                ->where('source', $source)
                ->where('status', 'proposed')
                ->delete();

            $groups = [
                ClaimTypes::ARTICLE_MENTIONS_PERSON => $payload['people'] ?? [],
                ClaimTypes::ARTICLE_MENTIONS_ORG => $payload['organizations'] ?? [],
                ClaimTypes::ARTICLE_MENTIONS_LOCATION => $payload['locations'] ?? [],
                ClaimTypes::ARTICLE_KEYWORD => $payload['keywords'] ?? [],
                ClaimTypes::ARTICLE_ISSUE_AREA => $payload['issue_areas'] ?? [],
            ];

            foreach ($groups as $claimType => $items) {
                if (! is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $value = $this->valueJson($claimType, $item);

                    if ($value === null) {
                        continue;
                    }

                    $normalizedValue = $this->normalizeValue($value);
                    $evidence = $this->normalizeEvidence($item['evidence'] ?? []);
                    $valueHash = $this->hashValue($normalizedValue);

                    Claim::query()->create([
                        'city_id' => $article->city_id,
                        'article_id' => $article->id,
                        'claim_type' => $claimType,
                        'subject_type' => null,
                        'subject_id' => null,
                        'value_json' => $normalizedValue,
                        'evidence_json' => $evidence !== [] ? $evidence : null,
                        'confidence' => $this->clampConfidence($item['confidence'] ?? 0.0),
                        'source' => $source,
                        'model' => $model !== '' ? $model : null,
                        'prompt_version' => $promptVersion !== '' ? $promptVersion : null,
                        'status' => 'proposed',
                        'value_hash' => $valueHash,
                    ]);
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private function valueJson(string $claimType, array $item): ?array
    {
        return match ($claimType) {
            ClaimTypes::ARTICLE_MENTIONS_PERSON => $this->personValue($item),
            ClaimTypes::ARTICLE_MENTIONS_ORG => $this->organizationValue($item),
            ClaimTypes::ARTICLE_MENTIONS_LOCATION => $this->locationValue($item),
            ClaimTypes::ARTICLE_KEYWORD => $this->keywordValue($item),
            ClaimTypes::ARTICLE_ISSUE_AREA => $this->issueAreaValue($item),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name: string, role?: string}|null
     */
    private function personValue(array $item): ?array
    {
        $name = $this->stringValue($item['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $value = ['name' => $name];
        $role = $this->stringValue($item['role'] ?? null);

        if ($role !== null) {
            $value['role'] = $role;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name: string, type_guess: string}|null
     */
    private function organizationValue(array $item): ?array
    {
        $name = $this->stringValue($item['name'] ?? null);
        $typeGuess = $this->normalizeOrganizationTypeGuess(
            $this->stringValue($item['type_guess'] ?? null)
        );

        if ($name === null || $typeGuess === null) {
            return null;
        }

        return [
            'name' => $name,
            'type_guess' => $typeGuess,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{name: string, address?: string}|null
     */
    private function locationValue(array $item): ?array
    {
        $name = $this->stringValue($item['name'] ?? null);

        if ($name === null) {
            return null;
        }

        $value = ['name' => $name];
        $address = $this->stringValue($item['address'] ?? null);

        if ($address !== null) {
            $value['address'] = $address;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{keyword: string}|null
     */
    private function keywordValue(array $item): ?array
    {
        $keyword = $this->stringValue($item['keyword'] ?? null);

        if ($keyword === null) {
            return null;
        }

        return ['keyword' => $keyword];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{slug: string}|null
     */
    private function issueAreaValue(array $item): ?array
    {
        $slug = $this->stringValue($item['slug'] ?? null);

        if ($slug === null) {
            return null;
        }

        $slug = strtolower($slug);

        return ['slug' => $slug];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array{quote: string, start?: int, end?: int}>
     */
    private function normalizeEvidence(array $items): array
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

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function normalizeValue(array $value): array
    {
        $value = array_filter($value, fn ($item) => $item !== null);

        ksort($value);

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function hashValue(array $value): string
    {
        $normalized = $this->normalizeValue($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sha1((string) $json);
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

    private function clampConfidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $confidence));
    }

    private function normalizeOrganizationTypeGuess(?string $typeGuess): ?string
    {
        if ($typeGuess === null) {
            return null;
        }

        $typeGuess = strtolower(trim($typeGuess));

        $allowed = [
            'government',
            'news_media',
            'nonprofit',
            'business',
            'school',
            'other',
        ];

        return in_array($typeGuess, $allowed, true) ? $typeGuess : 'other';
    }
}
