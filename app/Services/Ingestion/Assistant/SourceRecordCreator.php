<?php

namespace App\Services\Ingestion\Assistant;

use App\Models\EventSource;
use App\Models\Scraper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SourceRecordCreator
{
    /**
     * @param  array{kind: string, type: string, source_url: string, config: array<string, mixed>}  $discovery
     */
    public function create(
        array $discovery,
        int $cityId,
        string $name,
        ?int $organizationId,
        string $frequency,
        bool $active,
    ): Model {
        $this->ensureSourceIsNew($discovery, $cityId);

        return DB::transaction(function () use ($discovery, $cityId, $name, $organizationId, $frequency, $active): Model {
            if ($discovery['kind'] === 'event') {
                return EventSource::create([
                    'city_id' => $cityId,
                    'name' => $name,
                    'source_type' => $discovery['type'],
                    'source_url' => $discovery['source_url'],
                    'config' => $discovery['config'],
                    'frequency' => $frequency,
                    'is_active' => $active,
                    'health_status' => 'healthy',
                    'health_checked_at' => now(),
                    'health_error' => null,
                    'repair_proposal' => null,
                ]);
            }

            $slug = $this->uniqueScraperSlug($cityId, $name);

            return Scraper::create([
                'city_id' => $cityId,
                'organization_id' => $organizationId,
                'name' => $name,
                'slug' => $slug,
                'type' => $discovery['type'],
                'source_url' => $discovery['source_url'],
                'config' => $discovery['config'],
                'is_enabled' => $active,
                'frequency' => $frequency,
                'run_at' => in_array($frequency, ['daily', 'weekly'], true) ? Scraper::DEFAULT_RUN_AT : null,
                'health_status' => 'healthy',
                'health_checked_at' => now(),
                'health_error' => null,
                'repair_proposal' => null,
            ]);
        });
    }

    /**
     * @param  array{kind: string, source_url: string}  $discovery
     */
    private function ensureSourceIsNew(array $discovery, int $cityId): void
    {
        $exists = $discovery['kind'] === 'event'
            ? EventSource::query()->where('city_id', $cityId)->where('source_url', $discovery['source_url'])->exists()
            : Scraper::query()->where('city_id', $cityId)->where('source_url', $discovery['source_url'])->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sourceUrl' => __('This source is already configured for the selected city.'),
            ]);
        }
    }

    private function uniqueScraperSlug(int $cityId, string $name): string
    {
        $base = Str::slug($name) ?: 'source';
        $slug = $base;
        $suffix = 2;

        while (Scraper::query()->where('city_id', $cityId)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
