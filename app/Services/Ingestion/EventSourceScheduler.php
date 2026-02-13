<?php

namespace App\Services\Ingestion;

use App\Models\EventSource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class EventSourceScheduler
{
    /**
     * @return Collection<int, EventSource>
     */
    public function dueSources(CarbonInterface $nowUtc): Collection
    {
        $immutableNow = $nowUtc instanceof CarbonImmutable ? $nowUtc : CarbonImmutable::instance($nowUtc);

        $sources = EventSource::query()
            ->where('is_active', true)
            ->whereIn('source_type', ['ics', 'rss', 'json', 'json_api', 'html'])
            ->whereDoesntHave('runs', function ($query) {
                $query->whereIn('status', ['queued', 'running']);
            })
            ->get();

        return $sources
            ->filter(fn (EventSource $source): bool => $this->isDue($source, $immutableNow))
            ->values();
    }

    private function isDue(EventSource $source, CarbonImmutable $nowUtc): bool
    {
        $lastRunAt = $this->lastRunAtUtc($source);

        if (! $lastRunAt) {
            return true;
        }

        return match ($source->frequency ?? 'daily') {
            'hourly' => $nowUtc->greaterThanOrEqualTo($lastRunAt->addHour()),
            'daily' => $nowUtc->greaterThanOrEqualTo($lastRunAt->addDay()),
            'weekly' => $nowUtc->greaterThanOrEqualTo($lastRunAt->addWeek()),
            default => false,
        };
    }

    private function lastRunAtUtc(EventSource $source): ?CarbonImmutable
    {
        $lastRunAt = $source->last_run_at;

        if (! $lastRunAt) {
            return null;
        }

        return $lastRunAt instanceof CarbonImmutable
            ? $lastRunAt
            : CarbonImmutable::instance($lastRunAt);
    }
}
