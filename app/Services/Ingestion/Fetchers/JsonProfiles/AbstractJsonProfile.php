<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

abstract class AbstractJsonProfile implements JsonProfile
{
    public function __construct(
        protected readonly CalendarDateParser $dateParser,
        protected readonly EventNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveListPath(array $config): string
    {
        $listPath = Arr::get($config, 'list_path');

        if ($listPath === null) {
            $listPath = Arr::get($config, 'root_path');
        }

        if ($listPath === null) {
            throw new InvalidArgumentException('EventSource config.json.list_path or config.json.root_path is required');
        }

        return $listPath;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{request_url: string, payload: mixed}>
     */
    protected function fetchJsonPayloads(string $sourceUrl, array $config, string $timezone): array
    {
        $payloads = [];
        $urls = $this->shouldUseMonthLoop($config)
            ? $this->buildMonthLoopUrls($sourceUrl, $config, $timezone)
            : [$sourceUrl];

        $http = Http::timeout(15)->retry(2, 250);

        foreach ($urls as $url) {
            $response = $http->get($url);

            if (! $response->successful()) {
                throw new InvalidArgumentException('Failed to fetch JSON feed');
            }

            $payloads[] = [
                'request_url' => $url,
                'payload' => $response->json(),
            ];
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function shouldUseMonthLoop(array $config): bool
    {
        return $this->resolveMonthsForward($config) > 0;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveMonthsForward(array $config): int
    {
        $monthsForward = Arr::get($config, 'months_forward');

        if ($monthsForward === null) {
            $monthsForward = Arr::get($config, 'month_loop') ? 1 : 0;
        }

        if (! is_numeric($monthsForward)) {
            return 0;
        }

        $monthsForward = (int) $monthsForward;

        return $monthsForward > 0 ? $monthsForward : 0;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, string>
     */
    protected function buildMonthLoopUrls(string $sourceUrl, array $config, string $timezone): array
    {
        $template = $this->stringValue(Arr::get($config, 'url_template'));

        if ($template === '') {
            $template = $sourceUrl;
        }

        if (! str_contains($template, '{year}') || ! str_contains($template, '{month}')) {
            throw new InvalidArgumentException('Month loop requires {year} and {month} placeholders in the URL template.');
        }

        $monthsForward = $this->resolveMonthsForward($config);
        $startMonth = $this->resolveStartMonth($config, $timezone);
        $monthQuery = $this->normalizeMonthQuery(Arr::get($config, 'month_query', []));
        $urls = [];

        for ($offset = 0; $offset < $monthsForward; $offset++) {
            $month = $startMonth->copy()->addMonthsNoOverflow($offset);
            $url = str_replace(
                ['{year}', '{month}'],
                [$month->format('Y'), $month->format('n')],
                $template
            );
            $urls[] = $this->applyQuery($url, $monthQuery);
        }

        return $urls;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveStartMonth(array $config, string $timezone): Carbon
    {
        $startMonth = Arr::get($config, 'start_month');

        if (is_string($startMonth)) {
            $startMonth = trim($startMonth);

            if ($startMonth === '' || in_array(strtolower($startMonth), ['current', 'now', 'today'], true)) {
                return Carbon::now($timezone)->startOfMonth();
            }

            try {
                return Carbon::parse($startMonth, $timezone)->startOfMonth();
            } catch (Throwable) {
                return Carbon::now($timezone)->startOfMonth();
            }
        }

        if ($startMonth instanceof Carbon) {
            return $startMonth->copy()->startOfMonth();
        }

        return Carbon::now($timezone)->startOfMonth();
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeMonthQuery(mixed $query): array
    {
        if (! is_array($query)) {
            return [];
        }

        $normalized = [];

        foreach ($query as $key => $value) {
            if (! is_string($key) && ! is_int($key)) {
                continue;
            }

            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else {
                $value = (string) $value;
            }

            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $query
     */
    protected function applyQuery(string $url, array $query): string
    {
        if ($query === []) {
            return $url;
        }

        $existing = $this->extractQueryParams($url);
        $base = $this->stripQueryFromUrl($url);
        $merged = array_merge($existing, $query);

        return $merged === [] ? $base : $base.'?'.http_build_query($merged);
    }

    /**
     * @return array<string, string>
     */
    protected function extractQueryParams(string $url): array
    {
        $queryString = parse_url($url, PHP_URL_QUERY);

        if (! is_string($queryString) || $queryString === '') {
            return [];
        }

        $params = [];
        parse_str($queryString, $params);

        return array_map(
            fn (mixed $value) => is_array($value) ? implode(',', $value) : (string) $value,
            $params
        );
    }

    protected function stripQueryFromUrl(string $url): string
    {
        $base = strtok($url, '?');

        return $base !== false ? $base : $url;
    }

    protected function normalizeAllDay(mixed $explicit, bool $startAllDay, bool $endAllDay): bool
    {
        if (is_bool($explicit)) {
            return $explicit;
        }

        if (is_string($explicit)) {
            return in_array(strtolower($explicit), ['true', '1', 'yes'], true);
        }

        return $startAllDay || $endAllDay;
    }

    protected function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
