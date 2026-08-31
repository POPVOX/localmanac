<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DOCUMENTERS_FEED_URL = 'https://wichita-ks.documenters.org/feed/rss/';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->replaceLegacyDocumentersScrapers();
        $this->disableIncompleteEventSources();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $wichitaId = DB::table('cities')->where('slug', 'wichita')->value('id');

        if ($wichitaId) {
            DB::table('scrapers')
                ->where('city_id', $wichitaId)
                ->where('slug', 'wichita-documenters-reporting')
                ->delete();
        }
    }

    private function replaceLegacyDocumentersScrapers(): void
    {
        $wichitaId = DB::table('cities')->where('slug', 'wichita')->value('id');

        if (! $wichitaId) {
            return;
        }

        $legacyScrapers = DB::table('scrapers')
            ->where('city_id', $wichitaId)
            ->where('source_url', 'like', '%wichitadocumenters.org%')
            ->get(['id', 'organization_id']);
        $legacyIds = $legacyScrapers->pluck('id')->all();

        if ($legacyIds !== []) {
            DB::table('scrapers')
                ->whereIn('id', $legacyIds)
                ->update([
                    'is_enabled' => false,
                    'updated_at' => now(),
                ]);

            DB::table('scraper_runs')
                ->whereIn('scraper_id', $legacyIds)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => 'Source retired after the legacy Wichita Documenters domain was replaced.',
                    'updated_at' => now(),
                ]);
        }

        $organizationId = $legacyScrapers->pluck('organization_id')->filter()->first();
        $existing = DB::table('scrapers')
            ->where('city_id', $wichitaId)
            ->where('slug', 'wichita-documenters-reporting')
            ->first();
        $values = [
            'organization_id' => $organizationId,
            'name' => 'Wichita Documenters Reporting',
            'type' => 'rss',
            'source_url' => self::DOCUMENTERS_FEED_URL,
            'config' => json_encode([
                'feed_url' => self::DOCUMENTERS_FEED_URL,
                'default_content_type' => 'meeting_notes',
                'lang' => 'en',
                'max_items' => 50,
            ], JSON_UNESCAPED_SLASHES),
            'is_enabled' => true,
            'schedule_cron' => null,
            'frequency' => 'daily',
            'run_at' => '08:00:00',
            'run_day_of_week' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('scrapers')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('scrapers')->insert(array_merge($values, [
            'city_id' => $wichitaId,
            'slug' => 'wichita-documenters-reporting',
            'created_at' => now(),
        ]));
    }

    private function disableIncompleteEventSources(): void
    {
        $sources = DB::table('event_sources')
            ->join('cities', 'cities.id', '=', 'event_sources.city_id')
            ->where('event_sources.is_active', true)
            ->whereIn('event_sources.source_type', ['html', 'json', 'json_api'])
            ->get([
                'event_sources.id',
                'event_sources.source_type',
                'event_sources.source_url',
                'event_sources.config',
                'cities.slug as city_slug',
            ]);
        $disabledIds = [];

        foreach ($sources as $source) {
            $config = $this->decodeConfig($source->config);
            $profile = data_get($config, 'profile');
            $itemSelector = data_get($config, 'list.item_selector');
            $jsonConfig = data_get($config, 'json', $config);
            $hasJsonListPath = is_array($jsonConfig)
                && (array_key_exists('list_path', $jsonConfig) || array_key_exists('root_path', $jsonConfig));
            $isConfigured = match ($source->source_type) {
                'html' => $profile === 'wichita_chamber_events'
                    || (is_string($itemSelector) && trim($itemSelector) !== ''),
                'json', 'json_api' => $hasJsonListPath,
                default => true,
            };
            $copiedWichitaTemplate = $source->city_slug !== 'wichita'
                && $itemSelector === '.calendars .calendar ol li';
            $knownGoneSource = rtrim((string) $source->source_url, '/') === 'https://www.731arts.com/events';

            if (! $isConfigured || $copiedWichitaTemplate || $knownGoneSource) {
                $disabledIds[] = $source->id;
            }
        }

        if ($disabledIds === []) {
            return;
        }

        DB::table('event_sources')
            ->whereIn('id', $disabledIds)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

        DB::table('event_ingestion_runs')
            ->whereIn('event_source_id', $disabledIds)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_class' => null,
                'error_message' => 'Source disabled because its ingestion configuration is incomplete or obsolete.',
                'updated_at' => now(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeConfig(mixed $value): array
    {
        for ($attempt = 0; $attempt < 2 && is_string($value); $attempt++) {
            $decoded = json_decode($value, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }

            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }
};
