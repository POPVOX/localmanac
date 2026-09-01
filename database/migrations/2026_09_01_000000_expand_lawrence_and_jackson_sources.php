<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const LAWRENCE_NEWS_URL = 'https://lawrenceks.gov/feed/';

    private const LAWRENCE_EVENTS_URL = 'https://downtownlawrence.com/calendar/?ical=1';

    private const JACKSON_NEWS_URL = 'https://www.jacksontn.gov/syndication/rss.aspx?serverid=16361603&userid=5&feed=datasummary&key=yYMwiqADjV7%2fxC%2f1BoRFUoLqeAojaDFMbAfMsgj%2b5IOFvO0Iw906344cQC3C7HY2QgPPJyWNfuKiLWfM50o2MELGLQw%3d&target_object_id=16361698&portal_id=16361687&v=2.0&item_name=portlet_xml_title&item_description=portlet_xml_summary&item_pubdate=portlet_publish_date&max_items=16';

    private const JACKSON_EVENTS_URL = 'https://jacksontn.com/calendar/?ical=1';

    public function up(): void
    {
        $this->upsertArticleSource(
            citySlug: 'lawrence-ks',
            slug: 'city-of-lawrence-news',
            name: 'City of Lawrence News',
            sourceUrl: self::LAWRENCE_NEWS_URL,
        );
        $this->upsertEventSource(
            citySlug: 'lawrence-ks',
            name: 'Downtown Lawrence Events',
            sourceUrl: self::LAWRENCE_EVENTS_URL,
        );
        $this->upsertArticleSource(
            citySlug: 'jackson-tn',
            slug: 'city-of-jackson-public-notices',
            name: 'City of Jackson Public Notices & Press Releases',
            sourceUrl: self::JACKSON_NEWS_URL,
        );
        $this->upsertEventSource(
            citySlug: 'jackson-tn',
            name: 'Greater Jackson Chamber Events',
            sourceUrl: self::JACKSON_EVENTS_URL,
        );
    }

    public function down(): void
    {
        $this->deleteArticleSource('lawrence-ks', 'city-of-lawrence-news');
        $this->deleteEventSource('lawrence-ks', self::LAWRENCE_EVENTS_URL);
        $this->deleteArticleSource('jackson-tn', 'city-of-jackson-public-notices');
        $this->deleteEventSource('jackson-tn', self::JACKSON_EVENTS_URL);
    }

    private function upsertArticleSource(string $citySlug, string $slug, string $name, string $sourceUrl): void
    {
        $cityId = DB::table('cities')->where('slug', $citySlug)->value('id');

        if (! $cityId) {
            return;
        }

        $existing = DB::table('scrapers')
            ->where('city_id', $cityId)
            ->where('source_url', $sourceUrl)
            ->first();

        $existing ??= DB::table('scrapers')
            ->where('city_id', $cityId)
            ->where('slug', $slug)
            ->first();

        $values = [
            'name' => $name,
            'type' => 'rss',
            'source_url' => $sourceUrl,
            'config' => json_encode([
                'feed_url' => $sourceUrl,
                'default_content_type' => 'news',
                'lang' => 'en',
                'max_items' => 50,
            ], JSON_UNESCAPED_SLASHES),
            'is_enabled' => true,
            'health_status' => 'healthy',
            'health_checked_at' => now(),
            'health_error' => null,
            'repair_proposal' => null,
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
            'city_id' => $cityId,
            'organization_id' => null,
            'slug' => $slug,
            'created_at' => now(),
        ]));
    }

    private function upsertEventSource(string $citySlug, string $name, string $sourceUrl): void
    {
        $cityId = DB::table('cities')->where('slug', $citySlug)->value('id');

        if (! $cityId) {
            return;
        }

        $existing = DB::table('event_sources')
            ->where('city_id', $cityId)
            ->where('source_url', $sourceUrl)
            ->first();
        $values = [
            'name' => $name,
            'source_type' => 'ics',
            'source_url' => $sourceUrl,
            'config' => json_encode([
                'timezone' => 'America/Chicago',
            ], JSON_UNESCAPED_SLASHES),
            'frequency' => 'daily',
            'is_active' => true,
            'health_status' => 'healthy',
            'health_checked_at' => now(),
            'health_error' => null,
            'repair_proposal' => null,
            'last_run_at' => null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('event_sources')->where('id', $existing->id)->update($values);

            return;
        }

        DB::table('event_sources')->insert(array_merge($values, [
            'city_id' => $cityId,
            'created_at' => now(),
        ]));
    }

    private function deleteArticleSource(string $citySlug, string $slug): void
    {
        $cityId = DB::table('cities')->where('slug', $citySlug)->value('id');

        if ($cityId) {
            DB::table('scrapers')
                ->where('city_id', $cityId)
                ->where('slug', $slug)
                ->delete();
        }
    }

    private function deleteEventSource(string $citySlug, string $sourceUrl): void
    {
        $cityId = DB::table('cities')->where('slug', $citySlug)->value('id');

        if ($cityId) {
            DB::table('event_sources')
                ->where('city_id', $cityId)
                ->where('source_url', $sourceUrl)
                ->delete();
        }
    }
};
