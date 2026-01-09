<?php

namespace App\Livewire\Demo;

use App\Models\Article;
use App\Models\CivicAction;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Person;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Component;

class ArticleExplainer extends Component
{
    public Article $article;

    public function mount(Article $article): void
    {
        $this->article = $article->load([
            'city',
            'body',
            'scraper.organization',
            'analysis',
            'explainer',
            'opportunities',
            'articleEntities',
            'articleKeywords.keyword',
            'articleIssueAreas.issueArea',
            'civicActions',
            'processTimelineItems',
            'sources' => fn ($query) => $query->oldest('accessed_at')->oldest('id'),
        ]);

        $this->article->loadMissing('explainer');
    }

    /**
     * @return array<string, mixed>
     */
    public function analysisPayload(): array
    {
        $analysis = $this->article->analysis;

        if (! $analysis) {
            return [];
        }

        $finalScores = $this->normalizePayload($analysis->final_scores ?? null);

        if ($finalScores !== []) {
            return $finalScores;
        }

        return $this->normalizePayload($analysis->llm_scores ?? null);
    }

    /**
     * @return array{agency: ?float, timeliness: ?float, relevance: ?float, orientation: ?float, representation: ?float, comprehensibility: ?float}
     */
    public function dimensions(): array
    {
        $payload = $this->analysisPayload();
        $dimensions = $payload['dimensions'] ?? $payload;

        if (! is_array($dimensions)) {
            $dimensions = [];
        }

        return [
            'agency' => $this->floatOrNull($dimensions['agency'] ?? null),
            'timeliness' => $this->floatOrNull($dimensions['timeliness'] ?? null),
            'relevance' => $this->floatOrNull($dimensions['relevance'] ?? null),
            'orientation' => $this->floatOrNull($dimensions['orientation'] ?? null),
            'representation' => $this->floatOrNull($dimensions['representation'] ?? null),
            'comprehensibility' => $this->floatOrNull($dimensions['comprehensibility'] ?? null),
        ];
    }

    /**
     * @return array{agency: string, timeliness: string, relevance: string, orientation: string, representation: string, comprehensibility: string}
     */
    public function justifications(): array
    {
        $payload = $this->analysisPayload();
        $justifications = $payload['justifications'] ?? null;

        if (! is_array($justifications)) {
            $llmPayload = $this->normalizePayload($this->article->analysis?->llm_scores ?? null);
            $justifications = $llmPayload['justifications'] ?? [];
        }

        if (! is_array($justifications)) {
            $justifications = [];
        }

        return [
            'agency' => $this->stringValue($justifications['agency'] ?? null) ?? '',
            'timeliness' => $this->stringValue($justifications['timeliness'] ?? null) ?? '',
            'relevance' => $this->stringValue($justifications['relevance'] ?? null) ?? '',
            'orientation' => $this->stringValue($justifications['orientation'] ?? null) ?? '',
            'representation' => $this->stringValue($justifications['representation'] ?? null) ?? '',
            'comprehensibility' => $this->stringValue($justifications['comprehensibility'] ?? null) ?? '',
        ];
    }

    /**
     * @return array{
     *     whats_happening: string|null,
     *     why_it_matters: string|null,
     *     key_details: array<int, array{label: string|null, value: string|null, text: string|null}>,
     *     what_to_watch: array<int, array{label: string|null, value: string|null, text: string|null}>
     * }
     */
    public function explainerContent(): array
    {
        $explainer = $this->article->explainer;

        return [
            'whats_happening' => $this->stringValue($explainer?->whats_happening),
            'why_it_matters' => $this->stringValue($explainer?->why_it_matters),
            'key_details' => $this->normalizeExplainerItems($explainer?->key_details),
            'what_to_watch' => $this->normalizeExplainerItems($explainer?->what_to_watch),
        ];
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     title: string|null,
     *     description: string|null,
     *     location: string|null,
     *     url: string|null,
     *     starts_at: \DateTimeInterface|null,
     *     ends_at: \DateTimeInterface|null,
     *     has_time: bool,
     *     evidence: array<int, string>
     * }>
     */
    public function opportunities(): array
    {
        $opportunities = $this->normalizedOpportunities();

        usort($opportunities, function (array $left, array $right): int {
            $leftDate = $left['sort_at'] ?? null;
            $rightDate = $right['sort_at'] ?? null;

            if (! $leftDate && ! $rightDate) {
                return 0;
            }

            if (! $leftDate) {
                return 1;
            }

            if (! $rightDate) {
                return -1;
            }

            return $leftDate <=> $rightDate;
        });

        return array_map(fn (array $opportunity) => $this->stripSortKey($opportunity), $opportunities);
    }

    public function civicRelevanceScore(): ?float
    {
        $score = $this->article->analysis?->civic_relevance_score;

        return is_numeric($score) ? (float) $score : null;
    }

    /**
     * @return array<int, array{
     *     icon: string,
     *     title: string,
     *     subtitle: string|null,
     *     meta: array<int, string>,
     *     cta_label: string|null,
     *     cta_url: string|null,
     *     badge: string|null
     * }>
     */
    public function participationActions(): array
    {
        $actions = [];

        foreach ($this->opportunities() as $opportunity) {
            $action = $this->mapOpportunityToAction($opportunity);

            if ($action) {
                $actions[] = $action;
            }
        }

        $sourceAction = $this->sourceAction();

        if ($sourceAction) {
            $actions[] = $sourceAction;
        }

        return $actions;
    }

    public function statusLabel(): ?string
    {
        $status = $this->stringValue($this->article->status);

        if (! $status) {
            return null;
        }

        return Str::headline($status);
    }

    /**
     * @return array<int, array{
     *     label: string,
     *     date_label: string,
     *     has_date: bool,
     *     status: string,
     *     badge_text: string|null,
     *     note: string|null
     * }>
     */
    public function processTimelineItems(): array
    {
        $items = $this->article->processTimelineItems ?? collect();

        if ($items->isEmpty()) {
            return [];
        }

        $timezone = $this->article->city?->timezone ?: config('app.timezone');

        return $items->map(function ($item) use ($timezone): array {
            $date = $item->date?->clone()->timezone($timezone);
            $dateLabel = $date
                ? $date->format($item->has_time ? 'M j, Y g:i A' : 'M j, Y')
                : null;

            return [
                'label' => $item->label,
                'date_label' => $dateLabel,
                'has_date' => $date !== null,
                'status' => $item->status,
                'badge_text' => $item->badge_text,
                'note' => $item->note,
            ];
        })->all();
    }

    public function processTimelineStatusIcon(string $status): ?string
    {
        return match ($status) {
            'completed' => 'check',
            'current' => 'arrow-right',
            default => null,
        };
    }

    public function processTimelineStatusClasses(string $status): string
    {
        return match ($status) {
            'completed' => 'bg-emerald-500 text-white ring-emerald-500/40 dark:bg-emerald-400 dark:text-emerald-950 dark:ring-emerald-300/50',
            'current' => 'bg-amber-400 text-amber-950 ring-amber-400/50 dark:bg-amber-300 dark:text-amber-950 dark:ring-amber-300/60',
            'upcoming' => 'bg-transparent text-zinc-400 ring-zinc-300 dark:text-zinc-500 dark:ring-zinc-700',
            default => 'bg-transparent text-zinc-400 ring-zinc-200 dark:text-zinc-500 dark:ring-zinc-700',
        };
    }

    /**
     * @return array<string, array<int, array{name: string, type: string, secondary: string|null}>>
     */
    public function entitiesByGroup(): array
    {
        $entities = $this->article->articleEntities ?? collect();

        if ($entities->isEmpty()) {
            return [];
        }

        $entityIds = [
            'organization' => $entities->where('entity_type', 'organization')->pluck('entity_id')->filter()->all(),
            'person' => $entities->where('entity_type', 'person')->pluck('entity_id')->filter()->all(),
            'location' => $entities->where('entity_type', 'location')->pluck('entity_id')->filter()->all(),
        ];

        $organizations = Organization::query()
            ->whereIn('id', $entityIds['organization'])
            ->get()
            ->keyBy('id');

        $people = Person::query()
            ->whereIn('id', $entityIds['person'])
            ->get()
            ->keyBy('id');

        $locations = Location::query()
            ->whereIn('id', $entityIds['location'])
            ->get()
            ->keyBy('id');

        $groups = [
            __('Decision-makers') => [],
            __('Organizations mentioned') => [],
            __('People mentioned') => [],
            __('Locations mentioned') => [],
        ];

        foreach ($entities as $entity) {
            $type = strtolower((string) $entity->entity_type);
            $name = $this->stringValue($entity->display_name) ?? '';
            $secondary = null;

            if ($entity->entity_id) {
                if ($type === 'organization') {
                    $name = $organizations[$entity->entity_id]->name ?? $name;
                } elseif ($type === 'person') {
                    $name = $people[$entity->entity_id]->name ?? $name;
                } elseif ($type === 'location') {
                    $name = $locations[$entity->entity_id]->name ?? $name;
                }
            }

            if ($name === '') {
                continue;
            }

            if ($type === 'organization' && $this->isDecisionMaker($name)) {
                $groups[__('Decision-makers')][] = [
                    'name' => $name,
                    'type' => $type,
                    'secondary' => $secondary,
                ];

                continue;
            }

            if ($type === 'organization') {
                $groups[__('Organizations mentioned')][] = [
                    'name' => $name,
                    'type' => $type,
                    'secondary' => $secondary,
                ];

                continue;
            }

            if ($type === 'person') {
                $groups[__('People mentioned')][] = [
                    'name' => $name,
                    'type' => $type,
                    'secondary' => $secondary,
                ];

                continue;
            }

            if ($type === 'location') {
                $groups[__('Locations mentioned')][] = [
                    'name' => $name,
                    'type' => $type,
                    'secondary' => $secondary,
                ];
            }
        }

        return array_filter($groups);
    }

    public function civicActionIcon(CivicAction $action): string
    {
        return match ($action->kind) {
            'comment', 'submit_comment' => 'C',
            'meeting', 'hearing', 'attend_meeting' => 'M',
            'deadline', 'submit_bid' => 'D',
            'apply', 'submit_application' => 'A',
            'document', 'read_document' => 'R',
            default => 'P',
        };
    }

    public function render(): View
    {
        return view('livewire.demo.article-explainer')
            ->layout('layouts.demo', [
                'title' => $this->article->title ?? __('Article explainer'),
            ]);
    }

    /**
     * @return array<int, array{
     *     type: string,
     *     title: string|null,
     *     description: string|null,
     *     location: string|null,
     *     url: string|null,
     *     starts_at: \DateTimeInterface|null,
     *     ends_at: \DateTimeInterface|null,
     *     has_time: bool,
     *     evidence: array<int, string>,
     *     sort_at: \DateTimeInterface|null
     * }>
     */
    private function normalizedOpportunities(): array
    {
        $analysis = $this->article->analysis;
        $finalScores = $this->normalizePayload($analysis?->final_scores ?? null);
        $payloadOpportunities = $finalScores['opportunities'] ?? null;

        if (! is_array($payloadOpportunities)) {
            $payloadOpportunities = null;
        }

        if (is_array($payloadOpportunities) && $payloadOpportunities !== []) {
            return $this->normalizePayloadOpportunities($payloadOpportunities);
        }

        $opportunities = [];

        foreach ($this->article->opportunities ?? [] as $opportunity) {
            $startsAt = $opportunity->starts_at;
            $endsAt = $opportunity->ends_at;
            $sortAt = $startsAt ?? $endsAt;
            $title = $this->stringValue($opportunity->title);
            $notes = $this->stringValue($opportunity->notes);
            $type = $this->normalizeOpportunityType($opportunity->kind);
            $hasTime = $startsAt ? $startsAt->format('H:i:s') !== '00:00:00' : false;

            $opportunities[] = [
                'type' => $type,
                'title' => $title,
                'description' => $title ?? $notes,
                'location' => $this->stringValue($opportunity->location),
                'url' => $this->normalizeUrl($this->stringValue($opportunity->url)),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'has_time' => $hasTime,
                'sort_at' => $sortAt,
            ];
        }

        return $opportunities;
    }

    /**
     * @param  array<int, mixed>  $payloadOpportunities
     * @return array<int, array{
     *     type: string,
     *     title: string|null,
     *     description: string|null,
     *     location: string|null,
     *     url: string|null,
     *     starts_at: \DateTimeInterface|null,
     *     ends_at: \DateTimeInterface|null,
     *     has_time: bool,
     *     evidence: array<int, string>,
     *     sort_at: \DateTimeInterface|null
     * }>
     */
    private function normalizePayloadOpportunities(array $payloadOpportunities): array
    {
        $normalized = [];
        $timezone = $this->article->city?->timezone ?: config('app.timezone');

        foreach ($payloadOpportunities as $opportunity) {
            if (! is_array($opportunity)) {
                continue;
            }

            $type = $this->normalizeOpportunityType(
                $this->stringValue($opportunity['type'] ?? $opportunity['kind'] ?? null)
            );
            $description = $this->stringValue($opportunity['description'] ?? $opportunity['title'] ?? null);
            $title = $this->stringValue($opportunity['title'] ?? null);
            $location = $this->stringValue($opportunity['location'] ?? null);
            $url = $this->normalizeUrl($this->stringValue($opportunity['url'] ?? null));
            $date = $this->stringValue($opportunity['date'] ?? null);
            $time = $this->stringValue($opportunity['time'] ?? null);
            $hasTime = $time !== null;

            $startsAt = $this->parseOpportunityDate($date, $time, $timezone)
                ?? $this->parseDateValue($opportunity['starts_at'] ?? null);
            $endsAt = $this->parseDateValue($opportunity['ends_at'] ?? null);
            $sortAt = $startsAt ?? $endsAt;

            $normalized[] = [
                'type' => $type,
                'title' => $title,
                'description' => $description ?? $title,
                'location' => $location,
                'url' => $url,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'has_time' => $hasTime,
                'sort_at' => $sortAt,
            ];
        }

        return $normalized;
    }

    private function parseOpportunityDate(?string $date, ?string $time, string $timezone): ?Carbon
    {
        if (! $date) {
            return null;
        }

        $date = trim($date);
        $time = $time ? trim($time) : null;
        $input = trim(implode(' ', array_filter([$date, $time])));

        if ($input === '') {
            return null;
        }

        $formats = $time ? ['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d g:i A', 'Y-m-d g:i a'] : ['Y-m-d'];

        foreach ($formats as $format) {
            try {
                return Carbon::createFromFormat($format, $input, $timezone);
            } catch (\Throwable) {
                continue;
            }
        }

        try {
            return Carbon::parse($input, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseDateValue(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string|null, value: string|null, text: string|null}>
     */
    private function normalizeExplainerItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_string($item)) {
                $text = $this->stringValue($item);

                if ($text !== null) {
                    $normalized[] = ['label' => null, 'value' => null, 'text' => $text];
                }

                continue;
            }

            if (! is_array($item)) {
                continue;
            }

            $label = $this->stringValue($item['label'] ?? null);
            $value = $this->stringValue($item['value'] ?? null);

            if ($label !== null && $value !== null) {
                $normalized[] = ['label' => $label, 'value' => $value, 'text' => null];

                continue;
            }

            $text = $this->stringValue($item['text'] ?? null) ?? $label ?? $value;

            if ($text !== null) {
                $normalized[] = ['label' => null, 'value' => null, 'text' => $text];
            }
        }

        return $normalized;
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

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || Str::lower($value) === 'null') {
            return null;
        }

        return $value;
    }

    private function isDecisionMaker(string $name): bool
    {
        $name = Str::lower($name);

        return Str::contains($name, ['city', 'commission', 'board', 'council']);
    }

    private function normalizeOpportunityType(?string $type): string
    {
        $type = $type ? Str::snake(Str::lower($type)) : 'other';

        return match ($type) {
            'meeting', 'hearing' => 'meeting',
            'comment', 'comment_period', 'public_comment' => 'public_comment',
            'deadline' => 'deadline',
            'application' => 'application',
            default => 'other',
        };
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @return array{
     *     icon: string,
     *     title: string,
     *     subtitle: string|null,
     *     meta: array<int, string>,
     *     cta_label: string|null,
     *     cta_url: string|null,
     *     badge: string|null
     * }|null
     */
    private function mapOpportunityToAction(array $opportunity): ?array
    {
        $type = $this->stringValue($opportunity['type'] ?? null) ?? 'other';
        $title = $this->stringValue($opportunity['title'] ?? null);
        $description = $this->stringValue($opportunity['description'] ?? null);
        $location = $this->stringValue($opportunity['location'] ?? null);
        $startsAt = $opportunity['starts_at'] ?? null;
        $endsAt = $opportunity['ends_at'] ?? null;
        $hasTime = (bool) ($opportunity['has_time'] ?? false);
        $url = $this->normalizeUrl($this->stringValue($opportunity['url'] ?? null));

        if (! is_string($type)) {
            return null;
        }

        $meta = [];
        $dateLine = $this->formatOpportunityDate($startsAt, $hasTime);

        if ($dateLine) {
            $meta[] = $dateLine;
        }

        if ($location) {
            $meta[] = $location;
        }

        $action = [
            'icon' => 'bolt',
            'title' => __('Take Action'),
            'subtitle' => $description ?? $title,
            'meta' => $meta,
            'cta_label' => $url ? __('View details') : null,
            'cta_url' => $url,
            'badge' => null,
        ];

        if ($type === 'meeting') {
            $calendarUrl = $this->calendarUrl(
                $startsAt,
                $endsAt,
                $title ?? __('Attend the Hearing'),
                $location,
                $description
            );

            return [
                'icon' => 'calendar-days',
                'title' => __('Attend the Hearing'),
                'subtitle' => $title ?? $description,
                'meta' => $meta,
                'cta_label' => $calendarUrl ? __('Add to calendar') : ($url ? __('View details') : null),
                'cta_url' => $calendarUrl ?? $url,
                'badge' => null,
            ];
        }

        if ($type === 'public_comment') {
            return [
                'icon' => 'chat-bubble-left-right',
                'title' => __('Submit a Comment'),
                'subtitle' => $title ?? $description,
                'meta' => $meta,
                'cta_label' => $url ? __('Submit online') : null,
                'cta_url' => $url,
                'badge' => $this->deadlineBadge($endsAt ?? $startsAt, 'Closes'),
            ];
        }

        if ($type === 'deadline') {
            return [
                'icon' => 'clock',
                'title' => __('Deadline'),
                'subtitle' => $title ?? $description,
                'meta' => $meta,
                'cta_label' => $url ? __('View details') : null,
                'cta_url' => $url,
                'badge' => $this->deadlineBadge($endsAt ?? $startsAt, 'Due'),
            ];
        }

        if ($type === 'application') {
            return [
                'icon' => 'document-text',
                'title' => __('Apply / Submit'),
                'subtitle' => $title ?? $description,
                'meta' => $meta,
                'cta_label' => $url ? __('Apply now') : null,
                'cta_url' => $url,
                'badge' => $this->deadlineBadge($endsAt ?? $startsAt, 'Due'),
            ];
        }

        return $action;
    }

    /**
     * @return array{
     *     icon: string,
     *     title: string,
     *     subtitle: string|null,
     *     meta: array<int, string>,
     *     cta_label: string|null,
     *     cta_url: string|null,
     *     badge: string|null
     * }|null
     */
    private function sourceAction(): ?array
    {
        $url = $this->stringValue($this->article->canonical_url) ?? $this->article->primarySourceUrl();

        if (! $url) {
            return null;
        }

        return [
            'icon' => 'document-text',
            'title' => __('Read the Proposal'),
            'subtitle' => __('Full application and supporting documents'),
            'meta' => [],
            'cta_label' => __('View documents'),
            'cta_url' => $url,
            'badge' => null,
        ];
    }

    private function formatOpportunityDate(?\DateTimeInterface $date, bool $hasTime): ?string
    {
        if (! $date) {
            return null;
        }

        $timezone = $this->article->city?->timezone ?: config('app.timezone');
        $local = Carbon::instance($date)->timezone($timezone);
        $format = $hasTime ? 'M j, Y g:i A' : 'M j, Y';

        return $local->format($format);
    }

    private function deadlineBadge(?\DateTimeInterface $date, string $prefix): ?string
    {
        if (! $date) {
            return null;
        }

        $timezone = $this->article->city?->timezone ?: config('app.timezone');
        $local = Carbon::instance($date)->timezone($timezone);

        return trim($prefix.' '.$local->format('M j'));
    }

    private function calendarUrl(
        ?\DateTimeInterface $startsAt,
        ?\DateTimeInterface $endsAt,
        string $title,
        ?string $location,
        ?string $details
    ): ?string {
        if (! $startsAt) {
            return null;
        }

        $start = Carbon::instance($startsAt)->utc();
        $end = $endsAt
            ? Carbon::instance($endsAt)->utc()
            : $start->copy()->addHour();

        $query = http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => $start->format('Ymd\\THis\\Z').'/'.$end->format('Ymd\\THis\\Z'),
            'details' => $details,
            'location' => $location,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'https://www.google.com/calendar/render?'.$query;
    }

    /**
     * @param  array<string, mixed>  $opportunity
     * @return array<string, mixed>
     */
    private function stripSortKey(array $opportunity): array
    {
        unset($opportunity['sort_at']);

        return $opportunity;
    }
}
