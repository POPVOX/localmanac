<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Scraper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ImportScrapers extends Command
{
    protected $signature = 'scrapers:import {path : Path to scrapers.csv} {--city= : City slug or ID}';

    protected $description = 'Import legacy scrapers from CSV into Scraper records';

    public function handle(): int
    {
        $cityOption = (string) ($this->option('city') ?? 'wichita');

        if (! $this->option('city')) {
            $this->warn("--city not provided; defaulting to '{$cityOption}' (legacy site was single-city).");
        }

        $city = $this->findCity($cityOption);

        if (! $city) {
            $this->error("City '{$cityOption}' not found.");
            return self::FAILURE;
        }

        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("File '{$path}' not found or not readable.");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        if (! $handle) {
            $this->error("Unable to open '{$path}'.");
            return self::FAILURE;
        }

        $headers = fgetcsv($handle);
        $total = 0;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $skippedDetails = [];

        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $data = $this->rowToAssoc($headers, $row);

            $name = $data['name'] ?? null;

            if (! $name) {
                $skipped++;
                $this->recordSkip($skippedDetails, 'missing-name');
                continue;
            }

            $baseSlug = Str::slug($name);
            $legacyId = $data['id'] ?? null;
            $slug = $this->determineSlug($city->id, $baseSlug, $legacyId);

            $payload = [
                'city_id' => $city->id,
                'name' => $name,
                'slug' => $slug,
                'type' => $this->mapType($data['scrape_type'] ?? null),
                'source_url' => $data['scrape_url'] ?? null,
                'is_enabled' => filter_var($data['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'schedule_cron' => $this->mapFrequency($data['frequency'] ?? null),
                'config' => [
                    'organization_id' => $data['organization_id'] ?? null,
                    'parser_class' => $data['parser_class'] ?? null,
                    'special_instructions' => $data['special_instructions'] ?? null,
                    'legacy_id' => $legacyId,
                    'legacy_last_scraped_at' => $data['last_scraped_at'] ?? null,
                ],
            ];

            $scraper = Scraper::where('city_id', $city->id)->where('slug', $slug)->first();
            Scraper::updateOrCreate(
                ['city_id' => $city->id, 'slug' => $slug],
                $payload
            );

            if ($scraper) {
                $updated++;
            } else {
                $created++;
            }
        }

        fclose($handle);

        $this->info('Import complete');
        $this->line("Total rows: {$total}");
        $this->line("Created: {$created}");
        $this->line("Updated: {$updated}");
        $this->line("Skipped: {$skipped}");

        if ($skippedDetails) {
            $this->line('First skipped reasons:');
            foreach (array_slice($skippedDetails, 0, 10) as $detail) {
                $this->line("- {$detail}");
            }
        }

        return self::SUCCESS;
    }

    private function findCity(string $value): ?City
    {
        if (is_numeric($value)) {
            return City::find((int) $value);
        }

        return City::where('slug', $value)->first();
    }

    /**
     * @param array<int, string>|false $headers
     * @param array<int, string> $row
     * @return array<string, string|null>
     */
    private function rowToAssoc($headers, array $row): array
    {
        if (! $headers) {
            return [];
        }

        $assoc = [];
        foreach ($headers as $index => $key) {
            $assoc[$key] = $row[$index] ?? null;
        }

        return $assoc;
    }

    private function mapType(?string $type): string
    {
        return match ($type) {
            'rss_feed' => 'rss',
            'html_scrape' => 'html',
            'json_api' => 'api',
            default => 'unknown',
        };
    }

    private function mapFrequency(?string $frequency): ?string
    {
        return match ($frequency) {
            'hourly' => '0 * * * *',
            'daily' => '0 3 * * *',
            'weekly' => '0 3 * * 1',
            'monthly' => '0 3 1 * *',
            default => null,
        };
    }

    private function recordSkip(array &$skippedDetails, string $reason): void
    {
        if (count($skippedDetails) < 10) {
            $skippedDetails[] = $reason;
        }
    }

    private function determineSlug(int $cityId, string $baseSlug, ?string $legacyId): string
    {
        $baseSlug = $baseSlug ?: 'scraper';

        // If the base slug is unused, take it.
        $existing = Scraper::where('city_id', $cityId)->where('slug', $baseSlug)->first();
        if (! $existing) {
            return $baseSlug;
        }

        // If this looks like the same legacy scraper, keep the base slug so we update in place.
        $existingLegacyId = data_get($existing->config, 'legacy_id');
        if ($legacyId !== null && (string) $existingLegacyId === (string) $legacyId) {
            return $baseSlug;
        }

        // Otherwise, suffix until we find an unused slug.
        $counter = 2;
        while (Scraper::where('city_id', $cityId)->where('slug', "{$baseSlug}-{$counter}")->exists()) {
            $counter++;
        }

        return "{$baseSlug}-{$counter}";
    }
}
