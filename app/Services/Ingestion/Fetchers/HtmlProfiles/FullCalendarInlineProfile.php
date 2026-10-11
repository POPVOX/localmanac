<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class FullCalendarInlineProfile extends AbstractHtmlProfile
{
    public function supports(?string $profileName): bool
    {
        return $profileName === 'fullcalendar_inline';
    }

    public function fetchAndMap(EventSource $source, array $config, string $timezone): array
    {
        $url = $source->source_url;
        if (! $url) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $response = Http::timeout(15)->retry(2, 250)->get($url);
        if (! $response->successful()) {
            throw new InvalidArgumentException('Failed to fetch calendar page');
        }

        $crawler = new Crawler($response->body(), $url);
        $events = [];
        $seen = [];
        $maxItems = min(500, max(1, (int) ($config['max_items'] ?? 100)));
        $earliest = Carbon::now($timezone)->startOfDay()->subDays(max(0, (int) ($config['past_days'] ?? 7)));
        $latest = Carbon::now($timezone)->endOfDay()->addDays(max(1, (int) ($config['future_days'] ?? 365)));

        foreach ($crawler->filter($config['script_selector'] ?? 'script') as $node) {
            $script = (string) $node->textContent;
            preg_match_all('/\bevents\s*:\s*(\[)/', $script, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as $match) {
                foreach ($this->literalArray($script, $match[1]) as $record) {
                    if (! is_array($record) || ! is_string($record['start'] ?? null)
                        || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T|$)/', $record['start'])
                        || ! is_string($record['title'] ?? null) || trim($record['title']) === '') {
                        continue;
                    }

                    $start = $this->dateParser->parseIso($record['start'], $timezone);
                    if (! $start || $start['starts_at']->lessThan($earliest) || $start['starts_at']->greaterThan($latest)) {
                        continue;
                    }
                    $end = is_string($record['end'] ?? null) ? $this->dateParser->parseIso($record['end'], $timezone) : null;
                    $endsAt = $end['starts_at'] ?? null;
                    if ($endsAt && $endsAt->lessThanOrEqualTo($start['starts_at'])) {
                        $endsAt = null;
                    }
                    $eventUrl = $this->normalizer->normalizeUrl(is_string($record['url'] ?? null) ? $record['url'] : null, $url);
                    $key = ($eventUrl ?? $record['title']).'|'.$start['starts_at']->toIso8601String();
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;

                    $events[] = new EventDTO(
                        title: trim($record['title']),
                        startsAt: $start['starts_at'],
                        endsAt: $endsAt,
                        allDay: is_bool($record['allDay'] ?? null) ? $record['allDay'] : $start['all_day'],
                        locationName: $config['location_name'] ?? null,
                        locationAddress: null,
                        description: is_string($record['description'] ?? null) ? $record['description'] : null,
                        eventUrl: $eventUrl,
                        externalId: is_scalar($record['id'] ?? null) ? (string) $record['id'] : null,
                        sourceUrl: $eventUrl ?? $url,
                        rawPayload: ['fullcalendar' => $record],
                    );
                    if (count($events) >= $maxItems) {
                        return $events;
                    }
                }
            }
        }

        return $events;
    }

    /**
     * Read only literal data: no JavaScript execution, functions, expressions or globals.
     * Supports JSON strings, unquoted property names and trailing commas used by FullCalendar.
     *
     * @return array<int, mixed>
     */
    private function literalArray(string $script, int $offset): array
    {
        $tokens = [];
        $depth = 0;
        $pattern = '~\G\s*("(?:\\\\.|[^"\\\\])*"|-?\d+(?:\.\d+)?|[A-Za-z_$][A-Za-z0-9_$]*|[{}\[\]:,])~';

        while (preg_match($pattern, $script, $match, 0, $offset)) {
            $offset += strlen($match[0]);
            $token = $match[1];
            if (str_starts_with($token, '"')) {
                // JavaScript allows \' inside a double-quoted string; JSON does not.
                $token = preg_replace_callback('/\\\\./s', fn (array $escape): string => $escape[0] === "\\'" ? "'" : $escape[0], $token);
            }
            if (preg_match('/^[A-Za-z_$]/', $token)) {
                if (preg_match('/\G\s*:/', $script, $unused, 0, $offset)) {
                    $token = json_encode($token);
                } elseif (! in_array($token, ['true', 'false', 'null'], true)) {
                    return [];
                }
            }
            if ($token === '[' || $token === '{') {
                $depth++;
                if ($depth > 20) {
                    return [];
                }
            } elseif ($token === ']' || $token === '}') {
                $depth--;
                if (end($tokens) === ',') {
                    array_pop($tokens);
                }
            }
            $tokens[] = $token;
            if ($depth === 0) {
                $value = json_decode(implode('', $tokens), true);

                return is_array($value) && array_is_list($value) ? $value : [];
            }
        }

        return [];
    }
}
