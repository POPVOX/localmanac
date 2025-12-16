<?php

namespace App\Console\Commands;

use App\Models\City;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImportOrganizations extends Command
{
    protected $signature = 'organizations:import {path : Path to organizations.csv} {--city= : City slug or ID}';

    protected $description = 'Import legacy organizations into the organizations table';

    public function handle(): int
    {
        $cityOption = $this->option('city') ?? 'wichita';

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

        $columns = Schema::getColumnListing('organizations');
        while (($row = fgetcsv($handle)) !== false) {
            $total++;
            $data = $this->rowToAssoc($headers, $row);
            $legacyId = $data['id'] ?? null;
            $name = isset($data['name']) ? trim((string) $data['name']) : null;

            if (! $legacyId || ! $name) {
                $skipped++;
                continue;
            }

            $baseSlug = Str::slug($name) ?: 'organization';
            $slug = $this->uniqueSlug($city->id, $baseSlug, (int) $legacyId);

            $values = [
                'id' => (int) $legacyId,
                'city_id' => $city->id,
                'name' => $name,
                'slug' => $slug,
                'type' => $this->mapType($data['type'] ?? null),
                'website' => $data['website'] ?? null,
                'description' => $data['description'] ?? null,
                'created_at' => $data['created_at'] ?? now(),
                'updated_at' => $data['updated_at'] ?? now(),
            ];

            $values = array_intersect_key($values, array_flip($columns));

            $org = Organization::updateOrCreate(
                ['id' => (int) $legacyId],
                $values
            );

            if ($org->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        fclose($handle);

        $this->refreshSequence();

        $this->info('Organizations import complete');
        $this->line("Total rows: {$total}");
        $this->line("Created: {$created}");
        $this->line("Updated: {$updated}");
        $this->line("Skipped: {$skipped}");

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
        $value = strtolower(trim((string) $type));

        if ($value === '') {
            return 'unknown';
        }

        return match ($value) {
            'government', 'gov' => 'government',
            'nonprofit', 'ngo' => 'nonprofit',
            default => $value,
        };
    }

    private function uniqueSlug(int $cityId, string $baseSlug, int $ignoreId = 0): string
    {
        $slug = $baseSlug ?: 'organization';
        $counter = 1;

        while (
            Organization::where('city_id', $cityId)
                ->where('slug', $slug)
                ->when($ignoreId > 0, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $counter++;
            $slug = "{$baseSlug}-{$counter}";
        }

        return $slug;
    }

    private function refreshSequence(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table = (new Organization())->getTable();
        DB::statement("
            SELECT setval(
                pg_get_serial_sequence('{$table}', 'id'),
                GREATEST((SELECT MAX(id) FROM {$table}), 1)
            )
        ");
    }
}
