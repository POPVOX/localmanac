<?php

namespace App\Jobs;

use App\Models\EventIngestionRun;
use App\Models\EventSource;
use App\Services\Ingestion\EventIngestionRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunEventSourceIngestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $eventSourceId, public ?int $runId = null)
    {
        $this->onQueue('calendar');
    }

    public function handle(EventIngestionRunner $runner): void
    {
        $source = EventSource::query()
            ->with('city')
            ->findOrFail($this->eventSourceId);

        if ($this->runId) {
            $run = EventIngestionRun::query()
                ->where('event_source_id', $source->id)
                ->findOrFail($this->runId);

            $runner->runExisting($run);

            return;
        }

        $runner->run($source);
    }
}
