<?php

namespace App\Jobs;

use App\Models\ScraperRun;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class RunScraperRun implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onQueue('scraping');
    }

    public function handle(ScrapeRunner $runner): void
    {
        $run = ScraperRun::query()
            ->with('scraper')
            ->findOrFail($this->runId);

        $runner->runExisting($run);
    }

    public function failed(Throwable $exception): void
    {
        $run = ScraperRun::find($this->runId);

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception->getMessage(),
            'meta' => array_merge($run->meta ?? [], [
                'exception_class' => $exception::class,
                'trace_id' => (string) Str::uuid(),
            ]),
        ]);
    }
}
