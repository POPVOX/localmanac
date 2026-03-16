<?php

namespace App\Console\Commands;

use App\Services\Ingestion\EventTimestampNormalizer;
use Carbon\Carbon;
use Illuminate\Console\Command;

class EventsNormalizeTimestamps extends Command
{
    protected $signature = 'events:normalize-timestamps
        {--city= : City ID or slug}
        {--limit= : Maximum number of events to inspect}
        {--before= : Only normalize legacy rows created on or before this UTC timestamp}
        {--apply : Persist corrected starts_at and ends_at values}';

    protected $description = 'Audit or normalize legacy event timestamps into true UTC values';

    public function handle(EventTimestampNormalizer $normalizer): int
    {
        $city = $this->stringOption('city');
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

        $summary = $normalizer->normalize(
            city: $city,
            apply: $apply,
            limit: $limit,
            before: $before,
        );

        $this->line('scanned: '.$summary['scanned']);
        $this->line('needs_update: '.$summary['needs_update']);
        $this->line('updated: '.$summary['updated']);
        $this->line('skipped: '.$summary['skipped']);

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
