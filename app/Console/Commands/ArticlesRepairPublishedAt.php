<?php

namespace App\Console\Commands;

use App\Services\Ingestion\ArticlePublishedAtRepairer;
use Illuminate\Console\Command;

class ArticlesRepairPublishedAt extends Command
{
    protected $signature = 'articles:repair-published-at
        {--city= : City ID or slug}
        {--scraper= : Scraper ID or slug}
        {--feed= : Override feed URL for a single scraper}
        {--limit= : Maximum number of articles to inspect}
        {--apply : Persist corrected published_at values}';

    protected $description = 'Audit or repair published_at values for generic listing articles using feed timestamps';

    public function handle(ArticlePublishedAtRepairer $repairer): int
    {
        $city = $this->stringOption('city');
        $scraper = $this->stringOption('scraper');
        $feed = $this->stringOption('feed');
        $apply = (bool) $this->option('apply');
        $limit = $this->integerOption('limit');

        if ($feed !== null && $scraper === null) {
            $this->error('The --feed option requires --scraper so the override applies to exactly one scraper.');

            return self::FAILURE;
        }

        if (! $apply) {
            $this->line('Audit mode only. Pass --apply to persist changes.');
        }

        $summary = $repairer->repair(
            city: $city,
            scraperIdentifier: $scraper,
            apply: $apply,
            limit: $limit,
            feedOverride: $feed,
        );

        if ($summary['results'] === []) {
            $this->warn('No matching generic listing scrapers found.');

            return self::SUCCESS;
        }

        foreach ($summary['results'] as $result) {
            $label = "{$result['scraper_name']} ({$result['scraper_slug']})";
            $this->line($label);
            $this->line('  status: '.$result['status']);

            if ($result['feed_url']) {
                $this->line('  feed: '.$result['feed_url']);
            }

            $this->line('  scanned: '.$result['scanned']);
            $this->line('  matched: '.$result['matched']);
            $this->line('  needs_update: '.$result['needs_update']);
            $this->line('  updated: '.$result['updated']);
            $this->line('  unmatched: '.$result['unmatched']);
        }

        $this->newLine();
        $this->info('Totals');
        $this->line('  scrapers: '.$summary['scrapers']);
        $this->line('  scanned: '.$summary['scanned']);
        $this->line('  matched: '.$summary['matched']);
        $this->line('  needs_update: '.$summary['needs_update']);
        $this->line('  updated: '.$summary['updated']);
        $this->line('  unmatched: '.$summary['unmatched']);
        $this->line('  feedless: '.$summary['feedless']);

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function integerOption(string $key): ?int
    {
        $value = $this->stringOption($key);

        if ($value === null || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }
}
