<?php

namespace App\Services\Analysis;

use App\Models\Article;
use App\Models\CivicAction;
use App\Models\Claim;
use App\Support\Claims\ClaimTypes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CivicActionProjector
{
    public function projectForArticle(Article $article): void
    {
        $article->loadMissing(['analysis', 'city', 'scraper.organization']);

        $opportunities = $this->extractOpportunities($article);

        DB::transaction(function () use ($article, $opportunities) {
            CivicAction::query()
                ->where('article_id', $article->id)
                ->where('source', 'analysis_llm')
                ->delete();

            if ($opportunities === []) {
                return;
            }

            $rows = $this->mapOpportunities($article, $opportunities);

            if ($rows === []) {
                return;
            }

            CivicAction::query()->insert($rows);
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractOpportunities(Article $article): array
    {
        $analysis = $article->analysis;

        if (! $analysis) {
            return [];
        }

        $finalScores = $this->normalizePayload($analysis->final_scores ?? null);
        $opportunities = $finalScores['opportunities'] ?? null;

        if (is_array($opportunities) && $opportunities !== []) {
            return $opportunities;
        }

        return $this->extractOpportunitiesFromClaims($article);
    }

    /**
     * @param  array<int, array<string, mixed>>  $opportunities
     * @return array<int, array<string, mixed>>
     */
    private function mapOpportunities(Article $article, array $opportunities): array
    {
        $now = now();
        $timezone = $article->city?->timezone ?: config('app.timezone');
        $decisionBody = $this->inferDecisionBodyLabel($article);
        $actions = [];
        $dedupe = [];

        foreach ($opportunities as $opportunity) {
            if (! is_array($opportunity)) {
                continue;
            }

            $action = $this->buildAction($opportunity, $timezone, $decisionBody);

            if (! $action) {
                continue;
            }

            $dedupeKey = $action['dedupe_key'];

            if (isset($dedupe[$dedupeKey])) {
                continue;
            }

            $dedupe[$dedupeKey] = true;
            $actions[] = $action;
        }

        if ($actions === []) {
            return [];
        }

        usort($actions, fn (array $left, array $right): int => $this->compareActions($left, $right));

        $rows = [];
        $position = 1;

        foreach ($actions as $action) {
            $rows[] = [
                'article_id' => $article->id,
                'city_id' => $article->city_id,
                'kind' => $action['kind'],
                'title' => $action['title'],
                'subtitle' => $action['subtitle'],
                'description' => $action['description'],
                'url' => $action['cta_url'],
                'cta_label' => $action['cta_label'],
                'starts_at' => $action['starts_at'],
                'ends_at' => null,
                'location' => $action['location_line'],
                'badge_text' => $action['badge'],
                'status' => $this->statusForStartsAt($action['starts_at'], $now),
                'source' => 'analysis_llm',
                'source_payload' => $this->encodePayload($action['source_payload']),
                'position' => $position++,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @return array{
     *     kind: string,
     *     title: string,
     *     subtitle: string|null,
     *     description: string|null,
     *     badge: string|null,
     *     cta_label: string|null,
     *     cta_url: string|null,
     *     location_line: string|null,
     *     starts_at: \Illuminate\Support\Carbon|null,
     *     sort_at: \Illuminate\Support\Carbon|null,
     *     source_payload: array<string, mixed>,
     *     dedupe_key: string
     * }|null
     */
    private function buildAction(array $opportunity, string $timezone, ?string $decisionBody): ?array
    {
        $rawType = $this->stringValue($opportunity['type'] ?? $opportunity['kind'] ?? null);
        $normalizedType = $this->normalizeType($rawType);
        $description = $this->stringValue($opportunity['description'] ?? null);
        $rawUrl = $this->stringValue($opportunity['url'] ?? null);
        $kind = $this->classifyKind($normalizedType, $description, $rawUrl);
        $titleAndSubtitle = $this->titleAndSubtitle($kind, $normalizedType, $description, $decisionBody);
        $locationLine = $this->formatLocationLine($this->stringValue($opportunity['location'] ?? null));
        $hasTime = false;
        $startsAt = $this->parseStartsAt($opportunity, $timezone, $hasTime);
        $description = $this->normalizeDescription($description, $opportunity);
        $badge = $this->badgeText($kind, $startsAt, $hasTime, $timezone);
        $cta = $this->ctaForKind($kind, $rawUrl);

        return [
            'kind' => $kind,
            'title' => $titleAndSubtitle['title'],
            'subtitle' => $titleAndSubtitle['subtitle'],
            'description' => $description,
            'badge' => $badge,
            'cta_label' => $cta['label'],
            'cta_url' => $cta['url'],
            'location_line' => $locationLine,
            'starts_at' => $startsAt,
            'sort_at' => $kind === 'document' ? null : $startsAt,
            'source_payload' => $opportunity,
            'dedupe_key' => $this->actionHash($kind, $titleAndSubtitle['title'], $opportunity, $locationLine),
        ];
    }

    private function parseStartsAt(array $opportunity, string $timezone, ?bool &$hasTime = null): ?Carbon
    {
        $date = $this->stringValue($opportunity['date'] ?? null);
        $time = $this->stringValue($opportunity['time'] ?? null);

        if (! $date) {
            $hasTime = false;

            return null;
        }

        $hasTime = $time !== null;

        try {
            $local = Carbon::parse(trim($date.' '.$time), $timezone);

            if (! $hasTime) {
                $local = $local->setTime(12, 0);
            }

            return $local->clone()->utc();
        } catch (\Throwable) {
            $hasTime = false;

            return null;
        }
    }

    private function badgeText(string $kind, ?Carbon $startsAt, bool $hasTime, string $timezone): ?string
    {
        if (! $startsAt) {
            return null;
        }

        $local = $startsAt->clone()->timezone($timezone);
        $date = $local->format('M j');
        $dateTime = $hasTime ? $local->format('M j, g:i A') : $date;

        return match ($kind) {
            'comment', 'deadline', 'apply' => $local->isFuture()
                ? __('Closes :date', ['date' => $date])
                : $date,
            'meeting', 'hearing' => $dateTime,
            default => null,
        };
    }

    private function statusForStartsAt(?Carbon $startsAt, Carbon $now): string
    {
        if ($startsAt && $startsAt->lt($now->clone()->subDay())) {
            return 'closed';
        }

        return 'upcoming';
    }

    /**
     * @return array{title: string, subtitle: string|null}
     */
    private function titleAndSubtitle(string $kind, string $normalizedType, ?string $description, ?string $decisionBody): array
    {
        return match ($kind) {
            'comment' => [
                'title' => __('Submit a Comment'),
                'subtitle' => $this->commentSubtitle($normalizedType, $description),
            ],
            'hearing' => [
                'title' => __('Attend the Hearing'),
                // Only show a body name if we can infer exactly one; otherwise keep it generic.
                'subtitle' => $decisionBody,
            ],
            'meeting' => [
                'title' => __('Attend the Meeting'),
                'subtitle' => $decisionBody,
            ],
            'deadline' => [
                'title' => __("Don't Miss the Deadline"),
                'subtitle' => __('Deadline'),
            ],
            'apply' => [
                'title' => __('Submit Your Application'),
                'subtitle' => __('Application deadline'),
            ],
            'document' => [
                'title' => __('Read the Proposal'),
                'subtitle' => __('Full application and supporting documents'),
            ],
            default => [
                'title' => __('Participation Opportunity'),
                'subtitle' => null,
            ],
        };
    }

    private function commentSubtitle(string $normalizedType, ?string $description): string
    {
        $lower = Str::lower($description ?? '');

        if ($normalizedType === 'public_comment' || Str::contains($lower, 'public comment')) {
            return __('Public comment');
        }

        return __('Comment period');
    }

    private function inferDecisionBodyLabel(Article $article): ?string
    {
        $candidates = [];

        $claims = Claim::query()
            ->where('article_id', $article->id)
            ->where('claim_type', ClaimTypes::ARTICLE_MENTIONS_ORG)
            ->get(['value_json', 'confidence']);

        foreach ($claims as $claim) {
            $value = is_array($claim->value_json) ? $claim->value_json : [];
            $name = $this->stringValue($value['name'] ?? null);

            if (! $name) {
                continue;
            }

            if (! $this->isDecisionBodyName($name)) {
                continue;
            }

            $candidates[strtolower($name)] = $name;
        }

        $scraperOrganization = $article->scraper?->organization;

        if ($scraperOrganization && $scraperOrganization->type === 'government') {
            $name = $this->stringValue($scraperOrganization->name);

            if ($name !== null) {
                $candidates[strtolower($name)] = $name;
            }
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return array_values($candidates)[0];
    }

    private function isDecisionBodyName(string $name): bool
    {
        $lower = Str::lower($name);

        return Str::contains($lower, [
            'city council',
            'planning commission',
            'board',
            'authority',
            'committee',
            'council',
            'commission',
        ]);
    }

    /**
     * @param  array<string, mixed>  $opportunity
     */
    private function normalizeDescription(?string $description, array $opportunity): ?string
    {
        $text = $description ?? $this->descriptionFromEvidence($opportunity);

        if ($text === null) {
            return null;
        }

        $text = Str::squish($text);
        $sentence = $this->firstSentence($text);

        return Str::limit($sentence, 160);
    }

    private function firstSentence(string $text): string
    {
        $parts = preg_split('/(?<=[.!?])\s+/', $text, 2);

        if (! is_array($parts) || $parts === []) {
            return $text;
        }

        return $parts[0];
    }

    /**
     * @param  array<string, mixed>  $opportunity
     */
    private function descriptionFromEvidence(array $opportunity): ?string
    {
        $evidence = $opportunity['evidence'] ?? null;

        if (! is_array($evidence)) {
            return null;
        }

        foreach ($evidence as $item) {
            if (! is_array($item)) {
                continue;
            }

            $quote = $this->stringValue($item['quote'] ?? null);

            if ($quote !== null) {
                return Str::limit(Str::squish($quote), 140);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $opportunity
     */
    private function actionHash(string $kind, string $title, array $opportunity, ?string $locationLine): string
    {
        $date = $this->stringValue($opportunity['date'] ?? null);
        $time = $this->stringValue($opportunity['time'] ?? null);
        $url = $this->stringValue($opportunity['url'] ?? null);

        $payload = [
            'kind' => $kind,
            'title' => Str::lower($title),
            'date' => $date ? Str::lower($date) : null,
            'time' => $time ? Str::lower($time) : null,
            'url' => $url ? Str::lower($url) : null,
            'location' => $locationLine ? Str::lower($locationLine) : null,
        ];

        return sha1((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function normalizeType(?string $type): string
    {
        if (! $type) {
            return 'other';
        }

        return Str::snake(Str::lower($type));
    }

    private function classifyKind(string $normalizedType, ?string $description, ?string $url): string
    {
        $lower = Str::lower($description ?? '');

        if ($normalizedType === 'meeting' && Str::contains($lower, 'public hearing')) {
            return 'hearing';
        }

        $kind = match ($normalizedType) {
            'public_comment', 'comment' => 'comment',
            'meeting' => 'meeting',
            'hearing' => 'hearing',
            'deadline' => 'deadline',
            'application' => 'apply',
            'document', 'documents', 'doc', 'pdf' => 'document',
            default => 'other',
        };

        if ($kind === 'other' && $this->isDocumentUrl($url)) {
            return 'document';
        }

        return $kind;
    }

    private function isDocumentUrl(?string $url): bool
    {
        if (! $url) {
            return false;
        }

        $lower = Str::lower($url);

        // Fast path: explicit extension anywhere in the URL.
        if (Str::contains($lower, ['.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx'])) {
            return true;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (is_string($path) && $path !== '') {
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

            if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'], true)) {
                return true;
            }
        }

        // Heuristics for common “document endpoints” that don't end in a file extension.
        return Str::contains($lower, [
            'documentcenter',
            '/document/',
            'download',
            'attachment',
            'file=',
            'adid=',
            'archive.aspx?adid=',
        ]);
    }

    private function formatLocationLine(?string $location): ?string
    {
        if (! $location) {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $location))));

        if ($parts === []) {
            return null;
        }

        $slice = array_slice($parts, 0, count($parts) > 1 ? 2 : 1);

        return implode(', ', $slice);
    }

    /**
     * @return array{label: string|null, url: string|null}
     */
    private function ctaForKind(string $kind, ?string $url): array
    {
        if ($url === null) {
            return [
                'label' => null,
                'url' => null,
            ];
        }

        return match ($kind) {
            'comment' => [
                'label' => __('Submit online →'),
                'url' => $url,
            ],
            // We do not have an ICS generator yet, so don't imply this is a calendar link.
            'meeting', 'hearing' => [
                'label' => __('View details →'),
                'url' => $url,
            ],
            'document' => [
                'label' => __('View documents →'),
                'url' => $url,
            ],
            'deadline', 'apply' => [
                'label' => __('View details →'),
                'url' => $url,
            ],
            default => [
                'label' => null,
                'url' => null,
            ],
        };
    }

    private function compareActions(array $left, array $right): int
    {
        $leftWeight = $this->sortWeight($left['kind']);
        $rightWeight = $this->sortWeight($right['kind']);

        if ($leftWeight !== $rightWeight) {
            return $leftWeight <=> $rightWeight;
        }

        $leftSort = $left['sort_at'];
        $rightSort = $right['sort_at'];

        if (! $leftSort && ! $rightSort) {
            return 0;
        }

        if (! $leftSort) {
            return 1;
        }

        if (! $rightSort) {
            return -1;
        }

        return $leftSort->getTimestamp() <=> $rightSort->getTimestamp();
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function sortWeight(string $kind): int
    {
        return match ($kind) {
            'comment', 'deadline', 'apply' => 1,
            'meeting', 'hearing' => 2,
            'document' => 3,
            default => 4,
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractOpportunitiesFromClaims(Article $article): array
    {
        $claims = Claim::query()
            ->where('article_id', $article->id)
            ->where('claim_type', 'article_opportunity')
            ->whereIn('status', ['approved', 'proposed'])
            ->get(['value_json', 'evidence_json']);

        if ($claims->isEmpty()) {
            return [];
        }

        $opportunities = [];

        foreach ($claims as $claim) {
            $value = is_array($claim->value_json) ? $claim->value_json : [];

            if (isset($value['opportunities']) && is_array($value['opportunities'])) {
                foreach ($value['opportunities'] as $opportunity) {
                    if (is_array($opportunity)) {
                        $opportunities[] = $this->mergeClaimEvidence($opportunity, $claim->evidence_json);
                    }
                }

                continue;
            }

            if ($value !== []) {
                $opportunities[] = $this->mergeClaimEvidence($value, $claim->evidence_json);
            }
        }

        return $opportunities;
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @param  array<int, array<string, mixed>>|null  $evidence
     * @return array<string, mixed>
     */
    private function mergeClaimEvidence(array $opportunity, ?array $evidence): array
    {
        if (! isset($opportunity['evidence']) && is_array($evidence) && $evidence !== []) {
            $opportunity['evidence'] = $evidence;
        }

        return $opportunity;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
