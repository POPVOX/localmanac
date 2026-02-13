<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\EventSourceItem;
use App\Services\Ingestion\EventNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EventsDedupe extends Command
{
    protected $signature = 'events:dedupe {--dry-run : Preview duplicate groups without writing changes}';

    protected $description = 'Merge duplicate events by canonical city/title/start/location key';

    public function handle(EventNormalizer $normalizer): int
    {
        $events = Event::query()
            ->whereNotNull('starts_at')
            ->orderBy('id')
            ->get();

        $groups = [];

        foreach ($events as $event) {
            $canonicalHash = $this->canonicalHash($event, $normalizer);
            $groups[$canonicalHash] ??= [];
            $groups[$canonicalHash][] = $event;
        }

        $duplicateGroups = collect($groups)
            ->filter(fn (array $group): bool => count($group) > 1)
            ->values();

        if ($duplicateGroups->isEmpty()) {
            $this->info('No duplicate events found.');

            return self::SUCCESS;
        }

        $groupCount = $duplicateGroups->count();
        $duplicateCount = $duplicateGroups->sum(fn (array $group): int => count($group) - 1);

        $this->line("duplicate groups: {$groupCount}");
        $this->line("duplicate events: {$duplicateCount}");

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No changes were written.');

            return self::SUCCESS;
        }

        $mergedGroups = 0;
        $deletedEvents = 0;
        $movedSourceItems = 0;

        foreach ($duplicateGroups as $group) {
            $canonical = $this->selectCanonicalEvent($group);
            $duplicateIds = collect($group)
                ->pluck('id')
                ->reject(fn (int $id): bool => $id === $canonical->id)
                ->values();

            if ($duplicateIds->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($canonical, $duplicateIds, $normalizer, &$deletedEvents, &$movedSourceItems): void {
                $canonicalEvent = Event::query()->findOrFail($canonical->id);

                $duplicates = Event::query()
                    ->with('sourceItems')
                    ->whereIn('id', $duplicateIds->all())
                    ->orderBy('id')
                    ->get();

                foreach ($duplicates as $duplicate) {
                    $this->mergePreferredFields($canonicalEvent, $duplicate);

                    foreach ($duplicate->sourceItems as $sourceItem) {
                        $this->moveSourceItem($canonicalEvent, $sourceItem);
                        $movedSourceItems++;
                    }

                    $duplicate->delete();
                    $deletedEvents++;
                }

                $canonicalEvent->title = $this->sanitizeTitle((string) $canonicalEvent->title);
                $canonicalEvent->source_hash = $this->canonicalHash($canonicalEvent, $normalizer);
                $canonicalEvent->save();
            });

            $mergedGroups++;
        }

        $this->info('Dedupe complete.');
        $this->line("merged groups: {$mergedGroups}");
        $this->line("deleted events: {$deletedEvents}");
        $this->line("moved source items: {$movedSourceItems}");

        return self::SUCCESS;
    }

    private function selectCanonicalEvent(array $group): Event
    {
        return collect($group)
            ->sort(function (Event $a, Event $b): int {
                $scoreCompare = $this->eventQualityScore($b) <=> $this->eventQualityScore($a);

                if ($scoreCompare !== 0) {
                    return $scoreCompare;
                }

                return $b->id <=> $a->id;
            })
            ->firstOrFail();
    }

    private function eventQualityScore(Event $event): int
    {
        $score = 0;

        if (! str_contains((string) ($event->location_name ?? ''), '\\')) {
            $score += 2;
        }

        if (! str_contains((string) ($event->location_address ?? ''), '\\')) {
            $score += 1;
        }

        if (trim((string) ($event->event_url ?? '')) !== '') {
            $score += 1;
        }

        if (trim((string) ($event->description ?? '')) !== '') {
            $score += 1;
        }

        return $score;
    }

    private function mergePreferredFields(Event $canonical, Event $duplicate): void
    {
        $updates = [];

        foreach (['event_url', 'description', 'location_name', 'location_address', 'ends_at'] as $field) {
            $canonicalValue = $canonical->{$field};
            $duplicateValue = $duplicate->{$field};

            if ($this->isBlank($canonicalValue) && ! $this->isBlank($duplicateValue)) {
                $updates[$field] = $duplicateValue;
            }
        }

        if (
            is_string($canonical->location_name)
            && is_string($duplicate->location_name)
            && str_contains($canonical->location_name, '\\')
            && ! str_contains($duplicate->location_name, '\\')
        ) {
            $updates['location_name'] = $duplicate->location_name;
        }

        if (
            is_string($canonical->location_address)
            && is_string($duplicate->location_address)
            && str_contains($canonical->location_address, '\\')
            && ! str_contains($duplicate->location_address, '\\')
        ) {
            $updates['location_address'] = $duplicate->location_address;
        }

        if ($updates !== []) {
            $canonical->fill($updates)->save();
        }
    }

    private function moveSourceItem(Event $canonicalEvent, EventSourceItem $sourceItem): void
    {
        $target = EventSourceItem::query()->firstOrNew([
            'event_id' => $canonicalEvent->id,
            'event_source_id' => $sourceItem->event_source_id,
            'source_url' => $sourceItem->source_url,
            'external_id' => $sourceItem->external_id,
        ]);

        if ($target->raw_payload === null && $sourceItem->raw_payload !== null) {
            $target->raw_payload = $sourceItem->raw_payload;
        }

        if ($this->shouldUseFetchedAt($target->fetched_at, $sourceItem->fetched_at)) {
            $target->fetched_at = $sourceItem->fetched_at;
        }

        $target->save();
        $sourceItem->delete();
    }

    private function shouldUseFetchedAt($current, $candidate): bool
    {
        if ($candidate === null) {
            return false;
        }

        if ($current === null) {
            return true;
        }

        return $candidate->gt($current);
    }

    private function canonicalHash(Event $event, EventNormalizer $normalizer): string
    {
        $normalizedTitle = $normalizer->normalizeTitle($this->sanitizeTitle((string) $event->title));
        $normalizedLocation = $this->normalizeHashLocation(
            $normalizer,
            $this->sanitizeLocation($event->location_name),
            $this->sanitizeLocation($event->location_address),
        );

        $startsAtUtc = $event->starts_at?->copy()->utc()->format('Y-m-d H:i:s') ?? '';

        return sha1($event->city_id.'|'.$normalizedTitle.'|'.$startsAtUtc.'|'.$normalizedLocation);
    }

    private function normalizeHashLocation(EventNormalizer $normalizer, ?string $locationName, ?string $locationAddress): string
    {
        if ($locationName !== null && trim($locationName) !== '') {
            return $normalizer->normalizeTitle($locationName);
        }

        if ($locationAddress !== null && trim($locationAddress) !== '') {
            return $normalizer->normalizeTitle($locationAddress);
        }

        return '';
    }

    private function sanitizeLocation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = str_replace(['\\', "\u{00A0}"], ['', ' '], $cleaned);
        $cleaned = preg_replace('/\s*,\s*/', ', ', $cleaned) ?? '';
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        return $cleaned !== '' ? $cleaned : null;
    }

    private function sanitizeTitle(string $value): string
    {
        $cleaned = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $cleaned = trim(preg_replace('/\s+/', ' ', trim($cleaned)) ?? '');

        return $cleaned;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        return false;
    }
}
