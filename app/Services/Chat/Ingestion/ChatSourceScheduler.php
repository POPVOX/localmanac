<?php

namespace App\Services\Chat\Ingestion;

use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class ChatSourceScheduler
{
    /**
     * @return Collection<int, ChatSource>
     */
    public function dueSources(CarbonInterface $nowUtc): Collection
    {
        $immutableNow = $nowUtc instanceof CarbonImmutable ? $nowUtc : CarbonImmutable::instance($nowUtc);
        $this->expireStaleRuns();

        $sources = ChatSource::query()
            ->where('is_active', true)
            ->whereIn('frequency', ChatSource::FREQUENCIES)
            ->whereDoesntHave('runs', function ($query) {
                $query->freshActive();
            })
            ->get();

        return $sources
            ->filter(fn (ChatSource $source): bool => $this->isDue($source, $immutableNow))
            ->values();
    }

    private function isDue(ChatSource $source, CarbonImmutable $nowUtc): bool
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

    private function lastRunAtUtc(ChatSource $source): ?CarbonImmutable
    {
        $lastRunAt = $source->last_run_at;

        if (! $lastRunAt) {
            return null;
        }

        return $lastRunAt instanceof CarbonImmutable
            ? $lastRunAt
            : CarbonImmutable::instance($lastRunAt);
    }

    private function expireStaleRuns(): void
    {
        ChatSourceIngestionRun::query()
            ->staleActive()
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_class' => null,
                'error_message' => __('Run timed out before the worker started.'),
                'updated_at' => now(),
            ]);
    }
}
