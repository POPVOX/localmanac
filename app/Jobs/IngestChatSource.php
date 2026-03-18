<?php

namespace App\Jobs;

use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use App\Services\Chat\ChatSourceGuard;
use App\Services\Chat\Ingestion\ChatSourceCrawler;
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
    ) {
        $this->timeout = (int) config('chat.crawl_job_timeout', 1200);
    }

    /**
     * Execute the job.
     */
    public function handle(ChatSourceCrawler $crawler, ChatSourceGuard $chatSourceGuard): void
    {
        $source = ChatSource::query()->find($this->chatSourceId);

        if (! $source || ! $source->is_active) {
            return;
        }

        $pages = $crawler->crawl($source);
        $queue = (string) config('chat.crawl_queue', 'ingestion');
        $embeddingQueue = (string) config('chat.embedding_queue', 'embedding');
        $now = now();

        ChatSourcePage::query()
            ->where('chat_source_id', $source->id)
            ->get()
            ->filter(fn (ChatSourcePage $page): bool => $chatSourceGuard->isBlockedPage(
                $page->url,
                $page->canonical_url,
                $page->title,
                (string) ($page->content_text ?? '')
            ))
            ->each
            ->delete();

        foreach ($pages as $pageData) {
            $contentText = trim((string) ($pageData['content_text'] ?? ''));
            $contentHash = $contentText === '' ? null : sha1($contentText);

            $page = ChatSourcePage::query()->firstOrNew([
                'chat_source_id' => $source->id,
                'url' => (string) $pageData['url'],
            ]);

            $hasChanged = $this->force
                || $page->content_hash !== $contentHash
                || $page->content_length !== (int) ($pageData['content_length'] ?? 0);

            $page->fill([
                'canonical_url' => $pageData['canonical_url'] ?? null,
                'title' => $pageData['title'] ?? null,
                'content_type' => $pageData['content_type'] ?? null,
                'renderer' => $pageData['renderer'] ?? null,
                'status_code' => $pageData['status_code'] ?? null,
                'fetch_duration_ms' => $pageData['fetch_duration_ms'] ?? null,
                'content_text' => $contentText === '' ? null : $contentText,
                'content_length' => $pageData['content_length'] ?? null,
                'content_hash' => $contentHash,
                'fetched_at' => $now,
            ]);

            $page->save();

            if ($hasChanged) {
                EmbedChatSourcePage::dispatch($page->id)->onQueue($embeddingQueue);
            }
        }
    }
}
