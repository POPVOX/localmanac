<?php

namespace App\Jobs;

use App\Models\ChatSourcePage;
use App\Services\Chat\Ingestion\ChatSourcePageEmbedder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EmbedChatSourcePage implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $chatSourcePageId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ChatSourcePageEmbedder $embedder): void
    {
        $page = ChatSourcePage::query()->find($this->chatSourcePageId);

        if (! $page) {
            return;
        }

        $embedder->embed($page);
    }
}
