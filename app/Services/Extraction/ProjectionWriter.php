<?php

namespace App\Services\Extraction;

use App\Models\Article;
use App\Models\ArticleEntity;
use App\Models\ArticleIssueArea;
use App\Models\ArticleKeyword;
use App\Models\Claim;
use App\Models\IssueArea;
use App\Models\Keyword;
use App\Support\Claims\ClaimTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProjectionWriter
{
    public function write(Article $article, string $source = 'llm'): void
    {
        $minConfidence = (float) config('enrichment.projections.min_confidence', 0.55);

        DB::transaction(function () use ($article, $source, $minConfidence) {
            ArticleKeyword::query()
                ->where('article_id', $article->id)
                ->where('source', $source)
                ->delete();

            ArticleEntity::query()
                ->where('article_id', $article->id)
                ->where('source', $source)
                ->delete();

            ArticleIssueArea::query()
                ->where('article_id', $article->id)
                ->where('source', $source)
                ->delete();

            $claims = Claim::query()
                ->where('article_id', $article->id)
                ->where('source', $source)
                ->whereIn('status', ['approved', 'proposed'])
                ->where('confidence', '>=', $minConfidence)
                ->get(['claim_type', 'value_json', 'confidence']);

            if ($claims->isEmpty()) {
                return;
            }

            $now = now();

            $keywordRows = [];
            $entityRows = [];
            $issueAreaRows = [];

            $issueAreasBySlug = IssueArea::query()
                ->where('city_id', $article->city_id)
                ->get(['id', 'slug'])
                ->filter(fn (IssueArea $issueArea) => $issueArea->slug !== null)
                ->keyBy(fn (IssueArea $issueArea) => strtolower($issueArea->slug));

            foreach ($claims as $claim) {
                $claimType = (string) $claim->claim_type;
                $value = is_array($claim->value_json) ? $claim->value_json : [];
                $confidence = $this->clampConfidence($claim->confidence);

                if ($claimType === ClaimTypes::ARTICLE_KEYWORD) {
                    $keyword = $this->stringValue($value['keyword'] ?? null);

                    if ($keyword === null) {
                        continue;
                    }

                    $slug = Str::slug($keyword);

                    if ($slug === '') {
                        continue;
                    }

                    $keywordRecord = Keyword::query()->firstOrCreate(
                        [
                            'city_id' => $article->city_id,
                            'slug' => $slug,
                        ],
                        [
                            'name' => $keyword,
                        ]
                    );

                    $existing = $keywordRows[$keywordRecord->id] ?? null;
                    $confidence = $existing ? max($existing['confidence'], $confidence) : $confidence;

                    $keywordRows[$keywordRecord->id] = [
                        'article_id' => $article->id,
                        'keyword_id' => $keywordRecord->id,
                        'confidence' => $confidence,
                        'source' => $source,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }

                if ($claimType === ClaimTypes::ARTICLE_ISSUE_AREA) {
                    $slug = $this->stringValue($value['slug'] ?? null);

                    if ($slug === null) {
                        continue;
                    }

                    $slug = strtolower($slug);

                    $issueArea = $issueAreasBySlug->get($slug);

                    if (! $issueArea) {
                        continue;
                    }

                    $key = (string) $issueArea->id;
                    $existing = $issueAreaRows[$key] ?? null;
                    $confidence = $existing ? max($existing['confidence'], $confidence) : $confidence;

                    $issueAreaRows[$key] = [
                        'article_id' => $article->id,
                        'issue_area_id' => $issueArea->id,
                        'confidence' => $confidence,
                        'source' => $source,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    continue;
                }

                if (! in_array($claimType, [
                    ClaimTypes::ARTICLE_MENTIONS_PERSON,
                    ClaimTypes::ARTICLE_MENTIONS_ORG,
                    ClaimTypes::ARTICLE_MENTIONS_LOCATION,
                ], true)) {
                    continue;
                }

                $displayName = $this->stringValue($value['name'] ?? null);

                if ($displayName === null) {
                    continue;
                }

                $displayName = Str::squish($displayName);

                $entityType = match ($claimType) {
                    ClaimTypes::ARTICLE_MENTIONS_PERSON => 'person',
                    ClaimTypes::ARTICLE_MENTIONS_ORG => 'organization',
                    ClaimTypes::ARTICLE_MENTIONS_LOCATION => 'location',
                    default => 'other',
                };

                $entityKey = $entityType.'|'.strtolower($displayName);
                $existing = $entityRows[$entityKey] ?? null;
                $confidence = $existing ? max($existing['confidence'], $confidence) : $confidence;

                $entityRows[$entityKey] = [
                    'article_id' => $article->id,
                    'entity_type' => $entityType,
                    'entity_id' => null,
                    'display_name' => $displayName,
                    'confidence' => $confidence,
                    'source' => $source,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($keywordRows !== []) {
                ArticleKeyword::query()->insert(array_values($keywordRows));
            }

            if ($entityRows !== []) {
                ArticleEntity::query()->insert(array_values($entityRows));
            }

            if ($issueAreaRows !== []) {
                ArticleIssueArea::query()->insert(array_values($issueAreaRows));
            }
        });
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function clampConfidence(mixed $value): float
    {
        $confidence = is_numeric($value) ? (float) $value : 0.0;

        return max(0.0, min(1.0, $confidence));
    }
}
