<?php

namespace App\Console\Commands;

use App\Jobs\EmbedChatSourcePage;
use App\Models\ChatSource;
use App\Models\ChatSourcePage;
use App\Services\Chat\ChatSourceGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Throwable;

class ImportChatBrowserSnapshots extends Command
{
    protected $signature = 'chat-sources:import-browser-snapshots {path : JSON snapshots captured from public pages} {--city= : Required city ID} {--dry-run : Validate without writing}';

    protected $description = 'Index administrator-reviewed browser captures without claiming an automatic fetch succeeded';

    public function handle(ChatSourceGuard $guard): int
    {
        try {
            $cityId = filter_var($this->option('city'), FILTER_VALIDATE_INT);
            if (! $cityId || $cityId < 1) {
                throw new InvalidArgumentException('A valid --city ID is required.');
            }
            $path = (string) $this->argument('path');
            if (! is_file($path) || ! is_readable($path) || filesize($path) > 5_000_000) {
                throw new InvalidArgumentException('Snapshot JSON must be a readable file no larger than 5 MB.');
            }
            $pages = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            Validator::make(['pages' => $pages], [
                'pages' => ['required', 'array', 'min:1', 'max:100'],
                'pages.*.source_id' => ['required', 'integer'],
                'pages.*.url' => ['required', 'url:http,https', 'max:2048'],
                'pages.*.title' => ['required', 'string', 'max:500'],
                'pages.*.content_text' => ['required', 'string', 'min:200', 'max:200000'],
                'pages.*.captured_at' => ['required', 'date'],
            ])->validate();

            $sources = ChatSource::where('city_id', $cityId)->where('is_active', true)
                ->whereIn('id', array_column($pages, 'source_id'))->get()->keyBy('id');
            $seen = [];
            foreach ($pages as $page) {
                $source = $sources->get($page['source_id']);
                $url = parse_url($page['url']);
                $origin = $source ? parse_url($source->source_url) : [];
                if (! $source || isset($url['user']) || isset($url['pass'])
                    || strtolower($url['host'] ?? '') !== strtolower($origin['host'] ?? '')
                    || ($url['scheme'] ?? '') !== ($origin['scheme'] ?? '')
                    || ($url['port'] ?? null) !== ($origin['port'] ?? null)) {
                    throw new InvalidArgumentException('Each capture must belong to an active source in the requested city and match its origin.');
                }
                if ($guard->isBlockedPage($page['url'], $page['url'], $page['title'], $page['content_text'])) {
                    throw new InvalidArgumentException('Access-denied or challenge pages cannot be imported.');
                }
                $capturedAt = Carbon::parse($page['captured_at']);
                if ($capturedAt->gt(now()->addMinutes(5)) || $capturedAt->lt(now()->subDay())) {
                    throw new InvalidArgumentException('Captures must be from the past 24 hours, not a future date.');
                }
                $existing = $source->pages()->where('url', $page['url'])->first();
                if ($existing?->fetched_at?->gt($capturedAt)) {
                    throw new InvalidArgumentException('A newer version of this page is already indexed.');
                }
                $key = $source->id.'|'.$page['url'];
                if (isset($seen[$key])) {
                    throw new InvalidArgumentException('Duplicate source/page entries are not allowed.');
                }
                $seen[$key] = true;
            }

            if ($this->option('dry-run')) {
                $this->info(count($pages).' browser snapshots validated; no changes made.');

                return self::SUCCESS;
            }

            DB::transaction(function () use ($pages): void {
                foreach ($pages as $data) {
                    $page = ChatSourcePage::firstOrNew([
                        'chat_source_id' => $data['source_id'], 'url' => $data['url'],
                    ]);
                    $text = trim($data['content_text']);
                    $changed = ! $page->exists || $page->content_hash !== sha1($text) || ! $page->chunks()->exists();
                    $page->fill([
                        'canonical_url' => $data['url'], 'title' => $data['title'],
                        'content_type' => 'html', 'renderer' => 'browser_snapshot',
                        // The browser capture does not supply an HTTP response code.
                        'status_code' => null, 'fetch_duration_ms' => null,
                        'content_text' => $text, 'content_length' => mb_strlen($text),
                        'content_hash' => sha1($text), 'fetched_at' => Carbon::parse($data['captured_at']),
                    ])->save();
                    if ($changed) {
                        EmbedChatSourcePage::dispatch($page->id)->afterCommit();
                    }
                }
            });
            // Preserve automatic run history and last_run_at: an import does not
            // demonstrate that the worker can fetch this source on its own.
            $this->info(count($pages).' browser snapshots imported. Automatic crawl status is unchanged.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
