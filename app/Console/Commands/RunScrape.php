<?php

namespace App\Console\Commands;

use App\Models\Scraper;
use App\Services\Ingestion\ScrapeRunner;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RunScrape extends Command
{
    protected $signature = 'scrape:run {scraper : Scraper ID or slug}';

    protected $description = 'Run a scraper by ID or slug';

    public function handle(ScrapeRunner $runner): int
    {
        $identifier = (string) $this->argument('scraper');

        $scraperQuery = Scraper::query();

        if (ctype_digit($identifier)) {
            $scraperQuery->where('id', (int) $identifier);
        } else {
            $scraperQuery->where('slug', $identifier);
        }

        $scraper = $scraperQuery->first();

        if (! $scraper) {
            $this->error("Scraper not found: {$identifier}");

            return self::FAILURE;
        }

        try {
            $run = $runner->run($scraper);
        } catch (InvalidArgumentException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($run->status === 'failed') {
            $this->error($run->error_message ?? 'Scrape failed');

            return self::FAILURE;
        }

        $this->info('Scrape completed successfully.');
        $this->line("items_found: {$run->items_found}");
        $this->line("items_created: {$run->items_created}");
        $this->line("items_updated: {$run->items_updated}");

        $skipped = $run->meta['skipped_items'] ?? 0;
        if ($skipped > 0) {
            $this->line("skipped_items: {$skipped}");
        }

        return self::SUCCESS;
    }
}
