<?php

namespace App\Jobs;

use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class IngestChatSource implements ShouldQueue
{
    use Queueable;

    public int $timeout;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $chatSourceId,
        public bool $force = false,
        public ?int $runId = null,
        public bool $allowInactive = false,
    ) {
        $this->timeout = (int) config('chat.crawl_job_timeout', 1200);
        $this->onQueue((string) config('chat.crawl_queue', 'ingestion'));
    }

    /**
     * Execute the job.
     */
    public function handle(ChatSourceIngestionRunner $runner): void
    {
        if ($this->runId !== null) {
            $run = ChatSourceIngestionRun::query()
                ->where('chat_source_id', $this->chatSourceId)
                ->find($this->runId);

            if (! $run) {
                return;
            }

            $runner->runExisting($run, $this->force, $this->allowInactive);

            return;
        }

        $source = ChatSource::query()->find($this->chatSourceId);

        if (! $source) {
            return;
        }

        $runner->run($source, $this->force, $this->allowInactive);
    }
}
