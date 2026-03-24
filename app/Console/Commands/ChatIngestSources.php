<?php

namespace App\Console\Commands;

use App\Jobs\IngestChatSource;
use App\Models\ChatSource;
use App\Services\Chat\Ingestion\ChatSourceIngestionRunner;
use Illuminate\Console\Command;
use Throwable;

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
    public function handle(ChatSourceIngestionRunner $runner): int
    {
        $query = ChatSource::query();

        $includeInactive = (bool) $this->option('include-inactive');

        if (! $includeInactive) {
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
        $handled = 0;
        $skipped = 0;

        foreach ($sources as $source) {
            try {
                $run = $runner->createRun($source, $includeInactive);

                if ($sync) {
                    $result = $runner->runExisting($run, $force, $includeInactive);

                    if ($result->status === 'success') {
                        $handled++;
                    } else {
                        $skipped++;
                    }
                } else {
                    dispatch(new IngestChatSource($source->id, $force, $run->id, $includeInactive));
                    $handled++;
                }
            } catch (Throwable $exception) {
                report($exception);
                $skipped++;
            }
        }

        if ($sync) {
            $this->info(sprintf('Processed ingestion for %d source(s).', $handled));
        } else {
            $this->info(sprintf('Queued ingestion for %d source(s).', $handled));
        }

        if ($skipped > 0) {
            $this->line(sprintf('Skipped %d source(s).', $skipped));
        }

        return self::SUCCESS;
    }
}
