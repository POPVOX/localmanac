<?php

namespace App\Console\Commands;

use App\Jobs\RunEventSourceIngestion;
use App\Services\Ingestion\EventIngestionRunner;
use App\Services\Ingestion\EventSourceScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CalendarSchedule extends Command
{
    protected $signature = 'calendar:schedule';

    protected $description = 'Queue due event sources based on frequency';

    public function handle(EventSourceScheduler $scheduler, EventIngestionRunner $runner): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $dueSources = $scheduler->dueSources($nowUtc);

        $queued = 0;
        $skipped = 0;

        foreach ($dueSources as $source) {
            try {
                $run = $runner->createRun($source);

                RunEventSourceIngestion::dispatch($source->id, $run->id);

                $queued++;
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        $this->info('Schedule summary');
        $this->line("due: {$dueSources->count()}");
        $this->line("queued: {$queued}");
        $this->line("skipped: {$skipped}");

        return self::SUCCESS;
    }
}
