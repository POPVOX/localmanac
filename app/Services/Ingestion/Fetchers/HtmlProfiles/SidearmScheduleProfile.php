<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class SidearmScheduleProfile extends AbstractHtmlProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'sidearm_schedule';
    }

    public static function hasScheduleData(string $html): bool
    {
        [$table, $schedules] = self::scheduleData($html);

        return $schedules !== [];
    }

    public function fetchAndMap(EventSource $source, array $config, string $timezone): array
    {
        $url = trim((string) $source->source_url);
        if ($url === '') {
            throw new InvalidArgumentException('EventSource source_url is required');
        }
        $response = Http::timeout(15)->retry(2, 250)->get($url);
        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch athletics schedule');
        }

        [$table, $schedules] = self::scheduleData($response->body());
        $events = [];
        $seen = [];
        $earliest = Carbon::now($timezone)->startOfDay();
        $latest = $earliest->copy()->addDays(min(730, max(1, (int) ($config['future_days'] ?? 365))));
        $maxItems = min(500, max(1, (int) ($config['max_items'] ?? 100)));

        foreach (array_slice($schedules, 0, 20) as $scheduleRef) {
            $schedule = self::node($table, $scheduleRef);
            $games = self::field($table, $schedule, 'games');
            if (! is_array($games) || ! array_is_list($games) || self::field($table, $schedule, 'hidden') === true) {
                continue;
            }
            $school = self::field($table, $schedule, 'school_name');
            $sport = self::field($table, self::field($table, $schedule, 'sport'), 'title');
            if (! is_string($school) || ! is_string($sport)) {
                continue;
            }

            foreach (array_slice($games, 0, 1000) as $gameRef) {
                $game = self::node($table, $gameRef);
                $id = self::field($table, $game, 'id');
                $date = self::field($table, $game, 'date');
                $time = self::field($table, $game, 'time');
                $opponent = self::field($table, self::field($table, $game, 'opponent'), 'title');
                $homeAway = self::field($table, $game, 'location_indicator');
                $noPlay = self::field($table, $game, 'noplay_text');

                // Midnight/TBA placeholders are not all-day events. A daily
                // refresh can include these games once a real time is announced.
                if ((! is_int($id) && ! is_string($id)) || isset($seen[$id])
                    || ! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $date)
                    || ! is_string($time) || ! preg_match('/\d/', $time) || preg_match('/\b(TBA|TBD|or)\b/i', $time)
                    || self::field($table, $game, 'tbd') === true
                    || (is_string($noPlay) && preg_match('/cancel|postpon|suspend|no contest/i', $noPlay))
                    || ! is_string($opponent) || trim($opponent) === ''
                    || (($config['home_only'] ?? false) && $homeAway !== 'H')) {
                    continue;
                }

                $start = $this->dateParser->parseIso($date, $timezone);
                if (! $start || $start['starts_at']->lessThan($earliest) || $start['starts_at']->greaterThan($latest)) {
                    continue;
                }
                $endValue = self::field($table, $game, 'enddate');
                $end = is_string($endValue) ? $this->dateParser->parseIso($endValue, $timezone) : null;
                $endsAt = $end['starts_at'] ?? null;
                if ($endsAt && $endsAt->lessThanOrEqualTo($start['starts_at'])) {
                    $endsAt = null;
                }
                $location = self::field($table, $game, 'location');
                $facility = self::field($table, self::field($table, $game, 'facility'), 'title');
                $locationName = implode(' — ', array_filter([$facility, $location], fn ($value) => is_string($value) && trim($value) !== ''));
                $gameLink = self::field($table, $game, 'game_center_link');
                $eventUrl = self::field($table, $game, 'game_center_enabled') === true && is_string($gameLink)
                    ? $this->normalizer->normalizeUrl($gameLink, $url)
                    : $url;
                $promotion = self::field($table, $game, 'promotion');
                $title = trim($school).' '.trim($sport).($homeAway === 'A' ? ' at ' : ' vs ').trim($opponent);
                $seen[$id] = true;
                $events[] = new EventDTO(
                    title: $title,
                    startsAt: $start['starts_at'],
                    endsAt: $endsAt,
                    allDay: false,
                    locationName: $locationName !== '' ? $locationName : null,
                    locationAddress: null,
                    description: is_string($promotion) && trim($promotion) !== '' ? trim($promotion) : null,
                    eventUrl: $eventUrl,
                    externalId: (string) $id,
                    sourceUrl: $eventUrl,
                    sourceHash: sha1($source->city_id.'|sidearm|'.parse_url($url, PHP_URL_HOST).'|'.$id),
                    rawPayload: ['sidearm' => ['game_id' => $id, 'date' => $date, 'time' => $time, 'home_away' => $homeAway]],
                );
                if (count($events) >= $maxItems) {
                    return $events;
                }
            }
        }

        return $events;
    }

    /** @return array{array, array} */
    private static function scheduleData(string $html): array
    {
        $scripts = (new Crawler($html))->filter('script#__NUXT_DATA__[type="application/json"]');
        if ($scripts->count() === 0) {
            return [[], []];
        }
        $json = $scripts->first()->text('');
        if (strlen($json) > 10_000_000) {
            return [[], []];
        }
        $table = json_decode($json, true, 32);
        if (! is_array($table) || ! array_is_list($table) || count($table) > 100_000) {
            return [[], []];
        }
        $root = self::node($table, 0);
        $pinia = self::field($table, $root, 'pinia');
        $schedule = self::field($table, $pinia, 'schedule');
        $schedules = self::field($table, $schedule, 'schedules');

        return [$table, is_array($schedules) ? $schedules : []];
    }

    private static function field(array $table, mixed $record, string $key): mixed
    {
        return is_array($record) ? self::node($table, $record[$key] ?? null) : null;
    }

    /** Follow only requested fields in Nuxt's reference table; never evaluate JS or revive arbitrary types. */
    private static function node(array $table, mixed $reference): mixed
    {
        for ($depth = 0; $depth < 8; $depth++) {
            if (! is_int($reference) || $reference < 0 || ! array_key_exists($reference, $table)) {
                return null;
            }
            $value = $table[$reference];
            if (is_array($value) && array_is_list($value) && is_string($value[0] ?? null)) {
                if (! in_array($value[0], ['Reactive', 'ShallowReactive'], true)) {
                    return null;
                }
                $reference = $value[1] ?? null;

                continue;
            }

            return $value;
        }

        return null;
    }
}
