<?php

namespace App\Services\Ingestion;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventTimestampNormalizer
{
    /**
     * @return array{
     *     scanned: int,
     *     needs_update: int,
     *     updated: int,
     *     skipped: int
     * }
     */
    public function normalize(
        ?string $city = null,
        bool $apply = false,
        ?int $limit = null,
        ?Carbon $before = null,
    ): array {
        $events = $this->targetEvents($city, $limit, $before);

        if ($events->isEmpty()) {
            return [
                'scanned' => 0,
                'needs_update' => 0,
                'updated' => 0,
                'skipped' => 0,
            ];
        }

        $rawRows = DB::table('events')
            ->whereIn('id', $events->pluck('id')->all())
            ->get(['id', 'starts_at', 'ends_at'])
            ->keyBy('id');

        $needsUpdate = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($events as $event) {
            $timezone = $event->city?->timezone ?? config('app.timezone', 'UTC');
            $raw = $rawRows->get($event->id);

            if ($raw === null) {
                $skipped++;

                continue;
            }

            $normalizedStartsAt = $this->normalizeLegacyTimestamp($raw->starts_at, $timezone);
            $normalizedEndsAt = $this->normalizeLegacyTimestamp($raw->ends_at, $timezone);

            if ($normalizedStartsAt === null) {
                $skipped++;

                continue;
            }

            if (
                $this->timestampsMatch($event->starts_at, $normalizedStartsAt)
                && $this->timestampsMatch($event->ends_at, $normalizedEndsAt)
            ) {
                continue;
            }

            $needsUpdate++;

            if (! $apply) {
                continue;
            }

            $event->forceFill([
                'starts_at' => $normalizedStartsAt,
                'ends_at' => $normalizedEndsAt,
            ])->save();

            $updated++;
        }

        return [
            'scanned' => $events->count(),
            'needs_update' => $needsUpdate,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @return Collection<int, Event>
     */
    private function targetEvents(?string $city, ?int $limit, ?Carbon $before): Collection
    {
        return Event::query()
            ->with('city')
            ->whereNotNull('starts_at')
            ->when($city !== null && $city !== '', function ($query) use ($city): void {
                if (ctype_digit($city)) {
                    $query->where('city_id', (int) $city);

                    return;
                }

                $query->whereHas('city', fn ($builder) => $builder->where('slug', $city));
            })
            ->when($before !== null, fn ($query) => $query->where('created_at', '<=', $before))
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->get();
    }

    private function normalizeLegacyTimestamp(mixed $value, string $timezone): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $withoutOffset = preg_replace('/([+-]\d{2})(?::?\d{2})?$/', '', trim($value));

        if (! is_string($withoutOffset) || trim($withoutOffset) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($withoutOffset), $timezone)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    private function timestampsMatch(mixed $current, ?Carbon $resolved): bool
    {
        if ($current === null && $resolved === null) {
            return true;
        }

        if (! $current instanceof Carbon || $resolved === null) {
            return false;
        }

        return $current->copy()->utc()->equalTo($resolved->copy()->utc());
    }
}
