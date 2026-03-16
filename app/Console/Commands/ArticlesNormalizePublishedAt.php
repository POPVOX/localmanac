<?php

namespace App\Console\Commands;

use App\Services\Ingestion\ArticleTimestampRepairer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ArticlesNormalizePublishedAt extends Command
{
    protected $signature = 'articles:normalize-published-at
        {--city= : City ID or slug}
        {--scraper= : Scraper ID or slug}
        {--limit= : Maximum number of articles to inspect}
        {--before= : Only normalize legacy rows created on or before this UTC timestamp}
        {--apply : Persist corrected published_at and published_precision values}';

    protected $description = 'Audit or normalize legacy article published_at values and precision';

    public function handle(ArticleTimestampRepairer $repairer): int
    {
        $city = $this->stringOption('city');
        $scraper = $this->stringOption('scraper');
        $limit = $this->integerOption('limit');
        $before = $this->datetimeOption('before');
        $apply = (bool) $this->option('apply');

        if ($apply && $before === null) {
            $this->error('The --apply option requires --before so only legacy rows are normalized.');

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
            before: $before,
        );

        $this->line('scanned: '.$summary['scanned']);
        $this->line('resolved: '.$summary['resolved']);
        $this->line('needs_update: '.$summary['needs_update']);
        $this->line('updated: '.$summary['updated']);
        $this->line('unresolved: '.$summary['unresolved']);

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

    private function datetimeOption(string $key): ?Carbon
    {
        $value = $this->stringOption($key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value, 'UTC');
        } catch (\Throwable) {
            throw new \InvalidArgumentException("Invalid --{$key} datetime: {$value}");
        }
    }
}
