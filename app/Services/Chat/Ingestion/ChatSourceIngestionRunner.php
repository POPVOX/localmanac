<?php

namespace App\Services\Chat\Ingestion;

use App\Jobs\EmbedChatSourcePage;
use App\Models\ChatSource;
use App\Models\ChatSourceIngestionRun;
use App\Models\ChatSourcePage;
use App\Services\Chat\ChatSourceGuard;
use InvalidArgumentException;
use Throwable;

class ChatSourceIngestionRunner
{
    public function __construct(
        private readonly ChatSourceCrawler $crawler,
        private readonly ChatSourceGuard $chatSourceGuard,
    ) {}

    public function run(ChatSource $source, bool $force = false, bool $allowInactive = false): ChatSourceIngestionRun
    {
        $run = $this->createRun($source, $allowInactive);

        return $this->runExisting($run, $force, $allowInactive);
    }

    public function createRun(ChatSource $source, bool $allowInactive = false): ChatSourceIngestionRun
    {
        $this->assertRunnable($source, $allowInactive);

        return ChatSourceIngestionRun::create([
            'chat_source_id' => $source->id,
            'status' => 'queued',
            'pages_found' => 0,
            'pages_changed' => 0,
            'pages_embedded' => 0,
        ]);
    }

    public function runExisting(ChatSourceIngestionRun $run, bool $force = false, bool $allowInactive = false): ChatSourceIngestionRun
    {
        $run->loadMissing('chatSource');

        $source = $run->chatSource;
        $pagesFound = 0;
        $pagesChanged = 0;
        $pagesEmbedded = 0;
        $shouldUpdateLastRunAt = false;

        try {
            if (! $source) {
                throw new InvalidArgumentException('ChatSource is missing for this run');
            }

            $this->assertRunnable($source, $allowInactive);

            $run->forceFill([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
                'error_class' => null,
                'error_message' => null,
            ])->save();

            $shouldUpdateLastRunAt = true;

            $pages = $this->crawler->crawl($source);
            $pagesFound = count($pages);
            $queue = (string) config('chat.embedding_queue', 'embedding');
            $now = now();

            ChatSourcePage::query()
                ->where('chat_source_id', $source->id)
                ->get()
                ->filter(fn (ChatSourcePage $page): bool => $this->chatSourceGuard->isBlockedPage(
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

                $hasChanged = $force
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

                if (! $hasChanged) {
                    continue;
                }

                $pagesChanged++;
                $pagesEmbedded++;

                EmbedChatSourcePage::dispatch($page->id)->onQueue($queue);
            }

            $run->update([
                'status' => 'success',
                'finished_at' => now(),
                'pages_found' => $pagesFound,
                'pages_changed' => $pagesChanged,
                'pages_embedded' => $pagesEmbedded,
                'error_class' => null,
                'error_message' => null,
            ]);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'finished_at' => now(),
                'pages_found' => $pagesFound,
                'pages_changed' => $pagesChanged,
                'pages_embedded' => $pagesEmbedded,
                'error_class' => $exception::class,
                'error_message' => $exception->getMessage(),
            ]);
        }

        if ($shouldUpdateLastRunAt && $source) {
            $source->forceFill([
                'last_run_at' => now(),
            ])->save();
        }

        return $run->refresh();
    }

    private function assertRunnable(ChatSource $source, bool $allowInactive = false): void
    {
        if (! $allowInactive && ! $source->is_active) {
            throw new InvalidArgumentException('ChatSource is disabled');
        }

        if (trim((string) $source->source_url) === '') {
            throw new InvalidArgumentException('ChatSource source_url is required');
        }
    }
}
