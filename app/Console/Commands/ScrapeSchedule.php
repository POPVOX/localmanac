<?php

namespace App\Console\Commands;

use App\Jobs\RunScraperRun;
use App\Services\Ingestion\ScraperScheduler;
use App\Services\Ingestion\ScrapeRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ScrapeSchedule extends Command
{
    protected $signature = 'scrape:schedule';

    protected $description = 'Queue due scrapers based on their schedule';

    public function handle(ScraperScheduler $scheduler, ScrapeRunner $runner): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $dueScrapers = $scheduler->dueScrapers($nowUtc);

        $queued = 0;
        $skipped = 0;

        foreach ($dueScrapers as $scraper) {
            try {
                $run = $runner->createRun($scraper);

                RunScraperRun::dispatch($run->id)->onQueue('analysis');

                $queued++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        $this->info('Schedule summary');
        $this->line("due: {$dueScrapers->count()}");
        $this->line("queued: {$queued}");
        $this->line("skipped: {$skipped}");

        return self::SUCCESS;
    }
}
