<?php

namespace App\Console\Commands;

use App\Jobs\IngestChatSource;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use App\Services\Chat\Ingestion\ChatSourceScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class ChatSchedule extends Command
{
    protected $signature = 'chat:schedule';

    protected $description = 'Queue due chat sources based on frequency';

    /**
     * Execute the console command.
     */
    public function handle(ChatSourceScheduler $scheduler, ChatSourceIngestionRunner $runner): int
    {
        $nowUtc = CarbonImmutable::now('UTC');
        $dueSources = $scheduler->dueSources($nowUtc);
        $queued = 0;
        $skipped = 0;

        foreach ($dueSources as $source) {
            try {
                $run = $runner->createRun($source);

                dispatch(new IngestChatSource($source->id, false, $run->id));

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
