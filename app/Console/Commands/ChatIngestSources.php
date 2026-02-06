<?php

namespace App\Console\Commands;

use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use Illuminate\Console\Command;

class ChatIngestSources extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'chat:ingest-sources
                            {--city= : City ID or slug}
                            {--source=* : Chat source IDs}
                            {--include-inactive : Include inactive sources}
                            {--force : Re-embed pages even if content is unchanged}
                            {--sync : Run ingestion synchronously}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crawl and embed chat sources into the vector index.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $query = ChatSource::query();

        if (! $this->option('include-inactive')) {
            $query->where('is_active', true);
        }

        $sourceIds = $this->option('source');

        if (is_array($sourceIds) && $sourceIds !== []) {
            $query->whereIn('id', array_map('intval', $sourceIds));
        }

        $cityOption = $this->option('city');

        if (is_string($cityOption) && $cityOption !== '') {
            if (is_numeric($cityOption)) {
                $query->where('city_id', (int) $cityOption);
            } else {
                $query->whereHas('city', function ($builder) use ($cityOption) {
                    $builder->where('slug', $cityOption);
                });
            }
        }

        $sources = $query->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources found for ingestion.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $queue = (string) config('chat.crawl_queue', 'ingestion');

        foreach ($sources as $source) {
            $job = new IngestChatSource($source->id, $force);

            if ($sync) {
                dispatch_sync($job);
            } else {
                dispatch($job)->onQueue($queue);
            }
        }

        $this->info(sprintf('Queued ingestion for %d source(s).', $sources->count()));

        return self::SUCCESS;
    }
}
