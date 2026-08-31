<?php

namespace App\Console\Commands;

use App\Models\EventSource;
use App\Models\Scraper;
use App\Services\Ingestion\Assistant\SourceHealthChecker;
use Illuminate\Console\Command;

class CheckSourceHealth extends Command
{
    protected $signature = 'sources:check-health
                            {--stale-hours=24 : Recheck sources not checked within this many hours}
                            {--limit=25 : Maximum article and event sources to check per run}
                            {--include-inactive : Include paused sources so legacy failures can receive repair proposals}
                            {--all : Ignore the health check age}';

    protected $description = 'Preview ingestion sources and generate verified repair proposals for failures.';

    public function handle(SourceHealthChecker $checker): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subHours(max(1, (int) $this->option('stale-hours')));
        $all = (bool) $this->option('all');
        $includeInactive = (bool) $this->option('include-inactive');
        $checked = 0;
        $unhealthy = 0;
        $proposals = 0;

        $scrapers = Scraper::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_enabled', true))
            ->when(! $all, fn ($query) => $query->where(fn ($nested) => $nested
                ->whereNull('health_checked_at')
                ->orWhere('health_checked_at', '<', $cutoff)))
            ->orderBy('health_checked_at')
            ->limit($limit)
            ->get();

        foreach ($scrapers as $scraper) {
            $result = $checker->checkScraper($scraper);
            $checked++;
            $unhealthy += $result['status'] === 'unhealthy' ? 1 : 0;
            $proposals += $result['proposal'] ? 1 : 0;
        }

        $eventSources = EventSource::query()
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->when(! $all, fn ($query) => $query->where(fn ($nested) => $nested
                ->whereNull('health_checked_at')
                ->orWhere('health_checked_at', '<', $cutoff)))
            ->orderBy('health_checked_at')
            ->limit($limit)
            ->get();

        foreach ($eventSources as $source) {
            $result = $checker->checkEventSource($source);
            $checked++;
            $unhealthy += $result['status'] === 'unhealthy' ? 1 : 0;
            $proposals += $result['proposal'] ? 1 : 0;
        }

        $this->info("Checked {$checked} sources; {$unhealthy} unhealthy; {$proposals} verified repair proposals.");

        return self::SUCCESS;
    }
}
