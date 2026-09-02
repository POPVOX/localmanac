<?php

namespace App\Services\Ingestion\Assistant;

use App\Services\Chat\Ingestion\HttpPageFetcher;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\DomCrawler\Crawler;

class SourceDiscoveryService
{
    public function __construct(
        private readonly HttpPageFetcher $httpFetcher,
        private readonly ScraperAssistantSourceFetcher $renderedFetcher,
        private readonly ScraperConfigDrafter $scraperDrafter,
        private readonly EventSourceConfigDrafter $eventDrafter,
    ) {}

    /**
     * @return array{
     *     kind: 'article'|'event',
     *     type: string,
     *     source_url: string,
     *     name: string,
     *     config: array<string, mixed>,
     *     confidence: float,
     *     reasons: array<int, string>,
     *     warnings: array<int, string>,
     *     endpoints: array<int, array{url: string, type: string, label: string}>,
     *     renderer: string
     * }
     */
    public function discover(string $url): array
    {
        $url = trim($url);

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new InvalidArgumentException('Enter a valid http or https source URL.');
        }

        $probe = $this->httpFetcher->fetch($url);

        if ($probe === null) {
            throw new InvalidArgumentException('The source URL could not be reached.');
        }

        $body = trim((string) ($probe['body'] ?? ''));
        $contentType = mb_strtolower((string) ($probe['content_type'] ?? ''));
        $sourceUrl = (string) ($probe['url'] ?? $url);
        $renderer = (string) ($probe['renderer'] ?? 'http');
        $warnings = [];

        if ($this->looksLikeIcs($body, $contentType, $sourceUrl)) {
            $draft = $this->eventDrafter->draft('ics', $sourceUrl, $body);

            return $this->result(
                kind: 'event',
                type: 'ics',
                sourceUrl: $sourceUrl,
                name: $this->calendarName($body, $sourceUrl),
                draft: $draft,
                reasons: ['The URL returns an iCalendar feed with dated events.'],
                endpoints: [['url' => $sourceUrl, 'type' => 'ics', 'label' => 'Calendar feed']],
                renderer: $renderer,
            );
        }

        if ($this->looksLikeJson($body, $contentType)) {
            if ($this->looksLikeEventJson($body, $sourceUrl)) {
                $draft = $this->eventDrafter->draft('json_api', $sourceUrl, $body);

                return $this->result(
                    kind: 'event',
                    type: 'json_api',
                    sourceUrl: $sourceUrl,
                    name: $this->fallbackName($sourceUrl),
                    draft: $draft,
                    reasons: ['The endpoint returns structured records with event dates.'],
                    endpoints: [['url' => $sourceUrl, 'type' => 'json_api', 'label' => 'Events API']],
                    renderer: $renderer,
                );
            }

            $warnings[] = 'The URL returns JSON, but it does not expose recognizable event fields. It will be treated as an article listing.';
        }

        if ($this->looksLikeFeed($body, $contentType)) {
            $isEventFeed = $this->feedLooksEventFocused($body, $sourceUrl);
            $kind = $isEventFeed ? 'event' : 'article';
            $draft = $isEventFeed
                ? $this->eventDrafter->draft('rss', $sourceUrl, $body)
                : $this->scraperDrafter->draft('rss', $sourceUrl, $body);

            return $this->result(
                kind: $kind,
                type: 'rss',
                sourceUrl: $sourceUrl,
                name: $this->feedName($body, $sourceUrl),
                draft: $draft,
                reasons: [$isEventFeed
                    ? 'The feed is labeled as a calendar or events feed.'
                    : 'The URL returns a syndication feed with article entries.'],
                endpoints: [['url' => $sourceUrl, 'type' => 'rss', 'label' => $isEventFeed ? 'Event feed' : 'Article feed']],
                renderer: $renderer,
                extraWarnings: $warnings,
            );
        }

        if ($this->looksLikeJavascriptShell($body)) {
            try {
                $rendered = $this->renderedFetcher->fetch($sourceUrl);
                $body = (string) $rendered['html'];
                $sourceUrl = (string) $rendered['final_url'];
                $renderer = (string) $rendered['renderer'];
                $warnings = array_merge($warnings, $rendered['warnings']);
            } catch (\Throwable $exception) {
                $warnings[] = 'The page appears to rely on JavaScript and could not be fully rendered during discovery.';
            }
        }

        $endpoints = $this->discoverHtmlEndpoints($body, $sourceUrl);
        $civicPlusEndpoint = $this->discoverCivicPlusCalendarFeed($body, $sourceUrl, $endpoints);

        if ($civicPlusEndpoint !== null) {
            array_unshift($endpoints, $civicPlusEndpoint);
            $endpoints = collect($endpoints)
                ->unique(fn (array $endpoint): string => $endpoint['type'].'|'.$endpoint['url'])
                ->values()
                ->all();
        }

        $civicWebEndpoint = $this->discoverCivicWebMeetingsApi($body, $sourceUrl);

        if ($civicWebEndpoint !== null) {
            $endpointProbe = $this->probeEndpoint($civicWebEndpoint['probe_url']);
            $endpointBody = $endpointProbe['body'] ?? '[]';
            $draft = $this->eventDrafter->draft('json_api', $civicWebEndpoint['url'], $endpointBody);
            data_set($draft, 'config.json.url_template', $civicWebEndpoint['url_template']);
            data_set($draft, 'config.json.event_url_template', $civicWebEndpoint['event_url_template']);
            data_set($draft, 'config.json.months_forward', 12);
            data_set($draft, 'config.json.start_month', 'current');

            if ($endpointProbe === null) {
                $warnings[] = 'The CivicWeb meetings endpoint could not be inspected directly. The live preview will verify it.';
            }

            $name = $this->htmlName($body, $sourceUrl);

            return $this->result(
                kind: 'event',
                type: 'json_api',
                sourceUrl: $civicWebEndpoint['url'],
                name: Str::contains(mb_strtolower($name), ['meeting', 'calendar']) ? $name : $name.' Meetings',
                draft: $draft,
                reasons: ['A CivicWeb meeting calendar API was discovered on the portal.'],
                endpoints: [[
                    'url' => $civicWebEndpoint['url'],
                    'type' => 'json_api',
                    'label' => 'Meetings API',
                ]],
                renderer: $renderer,
                extraWarnings: $warnings,
            );
        }

        $eventScore = $this->eventPageScore($body, $sourceUrl);
        $eventEndpoint = collect($endpoints)->first(fn (array $endpoint): bool => in_array($endpoint['type'], ['ics', 'json_api'], true));
        $feedEndpoint = collect($endpoints)->firstWhere('type', 'rss');

        if (is_array($eventEndpoint)) {
            $endpointProbe = $this->probeEndpoint($eventEndpoint['url']);
            $endpointBody = $endpointProbe['body'] ?? $body;
            $draft = $this->eventDrafter->draft($eventEndpoint['type'], $eventEndpoint['url'], $endpointBody);

            if ($endpointProbe === null) {
                $warnings[] = 'The discovered calendar endpoint could not be inspected directly. The live preview will verify it.';
            }

            return $this->result(
                kind: 'event',
                type: $eventEndpoint['type'],
                sourceUrl: $eventEndpoint['url'],
                name: $eventEndpoint['type'] === 'ics' && $endpointProbe !== null
                    ? $this->calendarName($endpointBody, $eventEndpoint['url'])
                    : $this->htmlName($body, $sourceUrl),
                draft: $draft,
                reasons: ['A dedicated calendar endpoint was discovered on the page.'],
                endpoints: $endpoints,
                renderer: $renderer,
                extraWarnings: $warnings,
            );
        }

        if (is_array($feedEndpoint)) {
            $endpointProbe = $this->probeEndpoint($feedEndpoint['url']);
            $feedBody = $endpointProbe['body'] ?? $body;
            $feedIsEventFocused = $eventScore >= 3
                || $this->endpointLooksEventFocused($feedEndpoint)
                || ($endpointProbe !== null && $this->feedLooksEventFocused($feedBody, $feedEndpoint['url']));
            $kind = $feedIsEventFocused ? 'event' : 'article';
            $draft = $feedIsEventFocused
                ? $this->eventDrafter->draft('rss', $feedEndpoint['url'], $feedBody)
                : $this->scraperDrafter->draft('rss', $feedEndpoint['url'], $feedBody);

            if ($endpointProbe === null) {
                $warnings[] = 'The discovered feed could not be inspected directly. The live preview will verify it.';
            }

            return $this->result(
                kind: $kind,
                type: 'rss',
                sourceUrl: $feedEndpoint['url'],
                name: $endpointProbe !== null && $this->looksLikeFeed($feedBody, (string) ($endpointProbe['content_type'] ?? ''))
                    ? $this->feedName($feedBody, $feedEndpoint['url'])
                    : $this->htmlName($body, $sourceUrl),
                draft: $draft,
                reasons: [$feedIsEventFocused
                    ? 'The page is event-focused and publishes a dedicated feed.'
                    : 'A dedicated article feed was discovered on the page.'],
                endpoints: $endpoints,
                renderer: $renderer,
                extraWarnings: $warnings,
            );
        }

        if ($eventScore >= 4) {
            $draft = $this->eventDrafter->draft('html', $sourceUrl, $body);

            return $this->result(
                kind: 'event',
                type: 'html',
                sourceUrl: $sourceUrl,
                name: $this->htmlName($body, $sourceUrl),
                draft: $draft,
                reasons: ['The page contains repeated event, date, and calendar signals.'],
                endpoints: $endpoints,
                renderer: $renderer,
                extraWarnings: $warnings,
            );
        }

        $draft = $this->scraperDrafter->draft('html', $sourceUrl, $body);

        return $this->result(
            kind: 'article',
            type: 'html',
            sourceUrl: $sourceUrl,
            name: $this->htmlName($body, $sourceUrl),
            draft: $draft,
            reasons: ['The page is structured as a news or document listing.'],
            endpoints: $endpoints,
            renderer: $renderer,
            extraWarnings: $warnings,
        );
    }

    /**
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    private function probeEndpoint(string $url): ?array
    {
        $probe = $this->httpFetcher->fetch($url);

        if ($probe === null || trim((string) ($probe['body'] ?? '')) === '') {
            return null;
        }

        return $probe;
    }

    /**
     * @param  array{config: array<string, mixed>, confidence: float, warnings: array<int, string>, profile?: string, mode?: string}  $draft
     * @param  array<int, string>  $reasons
     * @param  array<int, array{url: string, type: string, label: string}>  $endpoints
     * @param  array<int, string>  $extraWarnings
     * @return array<string, mixed>
     */
    private function result(
        string $kind,
        string $type,
        string $sourceUrl,
        string $name,
        array $draft,
        array $reasons,
        array $endpoints,
        string $renderer,
        array $extraWarnings = [],
    ): array {
        return [
            'kind' => $kind,
            'type' => $type,
            'source_url' => $sourceUrl,
            'name' => $name,
            'config' => $draft['config'],
            'confidence' => max(0, min(1, (float) $draft['confidence'])),
            'reasons' => $reasons,
            'warnings' => array_values(array_unique(array_merge($extraWarnings, $draft['warnings']))),
            'endpoints' => $endpoints,
            'renderer' => $renderer,
        ];
    }

    private function looksLikeIcs(string $body, string $contentType, string $url): bool
    {
        return str_contains(mb_strtoupper(mb_substr($body, 0, 500)), 'BEGIN:VCALENDAR')
            || str_contains($contentType, 'text/calendar')
            || preg_match('/\.(ics|ical)(?:$|[?#])/i', $url) === 1;
    }

    private function looksLikeJson(string $body, string $contentType): bool
    {
        if (str_contains($contentType, 'json')) {
            return true;
        }

        $first = mb_substr(ltrim($body), 0, 1);

        if (! in_array($first, ['{', '['], true)) {
            return false;
        }

        json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function looksLikeEventJson(string $body, string $url): bool
    {
        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return false;
        }

        $encodedKeys = mb_strtolower(implode(' ', $this->jsonScalarPaths($payload)));
        $hasTitle = Str::contains($encodedKeys, ['title', 'name', 'summary']);
        $hasStart = Str::contains($encodedKeys, ['start', 'date', 'dtstart']);

        return ($hasTitle && $hasStart) || preg_match('/(?:events?|calendar)/i', $url) === 1;
    }

    /**
     * @param  array<string|int, mixed>  $value
     * @return array<int, string>
     */
    private function jsonScalarPaths(array $value, string $prefix = '', int $depth = 0): array
    {
        if ($depth > 7) {
            return [];
        }

        $paths = [];

        foreach ($value as $key => $child) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($child)) {
                $paths = array_merge($paths, $this->jsonScalarPaths($child, $path, $depth + 1));
            } else {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    private function looksLikeFeed(string $body, string $contentType): bool
    {
        $prefix = mb_strtolower(mb_substr(ltrim($body), 0, 1000));

        return str_contains($contentType, 'rss')
            || str_contains($contentType, 'atom')
            || str_contains($prefix, '<rss')
            || str_contains($prefix, '<feed');
    }

    private function feedLooksEventFocused(string $body, string $url): bool
    {
        $sample = mb_strtolower(mb_substr(strip_tags($body), 0, 8000));

        return preg_match('/(?:events?|calendar)/i', $url) === 1
            || Str::contains($sample, ['events calendar', 'upcoming events', 'event date', 'event time'])
            || preg_match('/<(?:[a-z0-9_-]+:)?(?:startdate|startdatetime|dtstart|eventdate|eventdates|when)\b/i', $body) === 1;
    }

    /**
     * @return array<int, array{url: string, type: string, label: string}>
     */
    private function discoverHtmlEndpoints(string $body, string $baseUrl): array
    {
        try {
            $crawler = new Crawler($body, $baseUrl);
        } catch (\Throwable) {
            return [];
        }

        $endpoints = [];

        $attributes = ['href', 'src', 'data-feed', 'data-feed-url', 'data-events-url', 'data-calendar-url', 'data-api-url', 'data-json-url', 'data-url'];

        foreach ($crawler->filter('link[href], a[href], iframe[src], script[src], [data-feed], [data-feed-url], [data-events-url], [data-calendar-url], [data-api-url], [data-json-url], [data-url]') as $node) {
            $context = implode(' ', [
                (string) $node->textContent,
                (string) $node->getAttribute('type'),
                (string) $node->getAttribute('rel'),
                (string) $node->getAttribute('id'),
                (string) $node->getAttribute('class'),
                $this->nodeHasCalendarContext($node) ? 'calendar event' : '',
            ]);

            foreach ($attributes as $attribute) {
                if (! $node->hasAttribute($attribute)) {
                    continue;
                }

                $this->addEndpointCandidate(
                    $endpoints,
                    (string) $node->getAttribute($attribute),
                    $attribute.' '.$context,
                    $baseUrl,
                );
            }
        }

        foreach ($crawler->filter('script:not([src])') as $node) {
            $script = html_entity_decode((string) $node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! Str::contains(mb_strtolower($script), ['event', 'calendar', 'rss', 'feed', '.ics', '.json', '/api/'])) {
                continue;
            }

            $script = str_replace('\\/', '/', $script);
            preg_match_all('/["\'](?<value>(?:https?:)?\\?\/\\?\/[^"\']+|(?:\.\.\/|\.\/|\/|(?:api|services?)\/)[^"\']+)["\']/i', $script, $matches);

            foreach (array_slice($matches['value'] ?? [], 0, 50) as $value) {
                $this->addEndpointCandidate(
                    $endpoints,
                    str_replace('\\/', '/', (string) $value),
                    'embedded script '.$script,
                    $baseUrl,
                );
            }
        }

        return array_values($endpoints);
    }

    /**
     * @param  array<string, array{url: string, type: string, label: string}>  $endpoints
     */
    private function addEndpointCandidate(array &$endpoints, string $value, string $context, string $baseUrl): void
    {
        $value = trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($value === '' || Str::startsWith($value, ['#', 'mailto:', 'javascript:', 'data:'])) {
            return;
        }

        $details = $this->classifyEndpoint($value, $context);

        if ($details === null) {
            return;
        }

        $resolved = $this->resolveUrl($value, $baseUrl);

        if ($resolved === null) {
            return;
        }

        $endpoints[$details['type'].'|'.$resolved] = [
            'url' => $resolved,
            'type' => $details['type'],
            'label' => $details['label'],
        ];
    }

    /**
     * @return array{type: 'ics'|'rss'|'json_api', label: string}|null
     */
    private function classifyEndpoint(string $value, string $context): ?array
    {
        $haystack = mb_strtolower($value.' '.$context);
        $eventFocused = Str::contains($haystack, ['event', 'calendar', 'meeting', 'agenda']);

        if (
            str_contains($haystack, 'text/calendar')
            || preg_match('/\.(ics|ical)(?:$|[?#])/i', $value) === 1
            || preg_match('~/common/modules/icalendar/icalendar\.aspx(?:$|[?#])~i', $value) === 1
            || str_contains($haystack, 'ical feed')
        ) {
            return ['type' => 'ics', 'label' => 'Calendar feed'];
        }

        if (
            str_contains($haystack, 'application/rss')
            || str_contains($haystack, 'application/atom')
            || preg_match('/(?:\/feed\/?(?:$|[?#])|\.rss(?:$|[?#])|\.xml(?:$|[?#]))/i', $value) === 1
            || preg_match('~/rssfeed\.aspx(?:$|[?#])~i', $value) === 1
        ) {
            return [
                'type' => 'rss',
                'label' => $eventFocused ? 'Event feed' : 'Article feed',
            ];
        }

        $looksJson = str_contains($haystack, 'application/json')
            || preg_match('/\.json(?:$|[?#])/i', $value) === 1
            || ($eventFocused && preg_match('~(?:/api/|/services?/|format=json|output=json)~i', $value) === 1);

        if ($looksJson && $eventFocused) {
            return ['type' => 'json_api', 'label' => 'Events API'];
        }

        return null;
    }

    /**
     * CivicPlus calendar pages link to an RSS chooser instead of the feed itself.
     * Follow that single chooser page and prefer its aggregate calendar feed.
     *
     * @param  array<int, array{url: string, type: string, label: string}>  $knownEndpoints
     * @return array{url: string, type: string, label: string}|null
     */
    private function discoverCivicPlusCalendarFeed(string $body, string $baseUrl, array $knownEndpoints): ?array
    {
        if (
            collect($knownEndpoints)->contains(fn (array $endpoint): bool => $endpoint['type'] === 'rss' && $this->endpointLooksEventFocused($endpoint))
            || ! $this->looksLikeCivicPlusCalendarPage($body, $baseUrl)
        ) {
            return null;
        }

        try {
            $crawler = new Crawler($body, $baseUrl);
        } catch (\Throwable) {
            return null;
        }

        $chooserUrl = null;

        foreach ($crawler->filter('a[href]') as $node) {
            $href = trim((string) $node->getAttribute('href'));
            $text = mb_strtolower(trim((string) $node->textContent));

            if (
                preg_match('~(?:^|/)rss\.aspx(?:$|[?#])~i', $href) !== 1
                || ! Str::contains(mb_strtolower($href.' '.$text), ['rss', 'calendar'])
            ) {
                continue;
            }

            $chooserUrl = $this->resolveUrl($href, $baseUrl);

            if ($chooserUrl !== null && $this->hasSameOrigin($chooserUrl, $baseUrl)) {
                break;
            }

            $chooserUrl = null;
        }

        if ($chooserUrl === null) {
            return null;
        }

        $chooserUrl = $this->withoutFragment($chooserUrl);
        $probe = $this->probeEndpoint($chooserUrl);

        if ($probe === null) {
            return null;
        }

        try {
            $chooser = new Crawler($probe['body'], $probe['url'] ?? $chooserUrl);
        } catch (\Throwable) {
            return null;
        }

        $fallback = null;

        foreach ($chooser->filter('a[href]') as $node) {
            $href = trim((string) $node->getAttribute('href'));

            if (preg_match('~/rssfeed\.aspx(?:$|[?#])~i', $href) !== 1) {
                continue;
            }

            $resolved = $this->resolveUrl($href, $chooserUrl);

            if ($resolved === null || ! $this->hasSameOrigin($resolved, $baseUrl)) {
                continue;
            }

            $haystack = mb_strtolower($href.' '.trim((string) $node->textContent));

            if (Str::contains($haystack, ['all-calendar.xml', 'all calendar'])) {
                return [
                    'url' => $resolved,
                    'type' => 'rss',
                    'label' => 'Event feed',
                ];
            }

            if ($fallback === null && $this->nodeHasCalendarContext($node)) {
                $fallback = [
                    'url' => $resolved,
                    'type' => 'rss',
                    'label' => 'Event feed',
                ];
            }
        }

        return $fallback;
    }

    private function looksLikeCivicPlusCalendarPage(string $body, string $url): bool
    {
        $lower = mb_strtolower($body);

        return preg_match('/\/calendar\.aspx(?:$|[?#])/i', $url) === 1
            && Str::contains($lower, ['civicengage', 'civicplus'])
            && str_contains($lower, 'rss.aspx');
    }

    /**
     * CivicWeb portals render meeting calendars from a public JSON service. The
     * landing page itself contains only empty calendar shells, so scraping its
     * HTML can never produce meetings reliably.
     *
     * @return array{url: string, url_template: string, probe_url: string, event_url_template: string}|null
     */
    private function discoverCivicWebMeetingsApi(string $body, string $baseUrl): ?array
    {
        $host = mb_strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $lower = mb_strtolower($body);

        if (
            ($host === '' || ! str_ends_with($host, '.civicweb.net'))
            || ! Str::contains($lower, ['/portal/meetingschedule.aspx', 'portal.meetingcalendarpage'])
        ) {
            return null;
        }

        $scheme = mb_strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');
        $serviceUrl = $origin.'/Services/MeetingsService.svc/meetings';
        $template = $serviceUrl.'?month={month}&year={year}&surroundingmonths=0';
        $localNow = now();
        $probeUrl = $serviceUrl.'?'.http_build_query([
            'month' => $localNow->format('n'),
            'year' => $localNow->format('Y'),
            'surroundingmonths' => 0,
        ]);

        return [
            'url' => $serviceUrl,
            'url_template' => $template,
            'probe_url' => $probeUrl,
            'event_url_template' => $origin.'/Portal/MeetingInformation.aspx?Org=Cal&Id={Id}',
        ];
    }

    private function nodeHasCalendarContext(\DOMNode $node): bool
    {
        $current = $node;

        for ($depth = 0; $depth < 5 && $current !== null; $depth++) {
            $context = mb_strtolower((string) $current->textContent);

            if ($current instanceof \DOMElement) {
                $context .= ' '.mb_strtolower(implode(' ', [
                    $current->getAttribute('id'),
                    $current->getAttribute('class'),
                    $current->getAttribute('name'),
                ]));
            }

            if (str_contains($context, 'calendar')) {
                return true;
            }

            $current = $current->parentNode;
        }

        return false;
    }

    private function withoutFragment(string $url): string
    {
        return Str::before($url, '#');
    }

    private function hasSameOrigin(string $url, string $baseUrl): bool
    {
        return mb_strtolower((string) parse_url($url, PHP_URL_SCHEME)) === mb_strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME))
            && mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === mb_strtolower((string) parse_url($baseUrl, PHP_URL_HOST))
            && parse_url($url, PHP_URL_PORT) === parse_url($baseUrl, PHP_URL_PORT);
    }

    private function eventPageScore(string $body, string $url): int
    {
        $lower = mb_strtolower($body);
        $score = preg_match('/(?:events?|calendar)/i', $url) === 1 ? 2 : 0;

        if (
            preg_match('/["\']@type["\']\s*:\s*["\']event["\']/i', $body) === 1
            || Str::contains($lower, ['itemtype="https://schema.org/event"', 'itemtype="http://schema.org/event"'])
        ) {
            $score += 4;
        }

        if (Str::contains($lower, ['upcoming events', 'events calendar', 'calendar of events'])) {
            $score += 2;
        }

        if (preg_match_all('/class=["\'][^"\']*(?:event|calendar)[^"\']*["\']/i', $body) >= 2) {
            $score += 2;
        }

        if (preg_match_all('/<time\b[^>]*(?:datetime|itemprop=["\']startDate)/i', $body) >= 2) {
            $score += 2;
        }

        return $score;
    }

    /**
     * @param  array{url: string, type: string, label: string}  $endpoint
     */
    private function endpointLooksEventFocused(array $endpoint): bool
    {
        return Str::contains(mb_strtolower($endpoint['url'].' '.$endpoint['label']), ['event', 'calendar']);
    }

    private function looksLikeJavascriptShell(string $body): bool
    {
        $textLength = mb_strlen(trim(strip_tags($body)));
        $lower = mb_strtolower($body);

        return $textLength < 500 && Str::contains($lower, ['id="__next"', 'id="app"', 'enable javascript', 'data-reactroot']);
    }

    private function htmlName(string $body, string $url): string
    {
        try {
            $crawler = new Crawler($body, $url);

            foreach (['meta[property="og:site_name"]', 'title', 'h1'] as $selector) {
                $nodes = $crawler->filter($selector);

                if ($nodes->count() === 0) {
                    continue;
                }

                $value = $selector[0] === 'm'
                    ? trim((string) $nodes->first()->attr('content'))
                    : trim($nodes->first()->text(''));

                if ($value !== '') {
                    return Str::limit(preg_replace('/\s+[|–—-]\s+.+$/u', '', $value) ?: $value, 120, '');
                }
            }
        } catch (\Throwable) {
            // Fall through to the host name.
        }

        return $this->fallbackName($url);
    }

    private function feedName(string $body, string $url): string
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $title = $xml ? trim((string) ($xml->channel->title ?? $xml->title ?? '')) : '';

        return $title !== '' ? Str::limit($title, 120, '') : $this->fallbackName($url);
    }

    private function calendarName(string $body, string $url): string
    {
        if (preg_match('/^X-WR-CALNAME:(.+)$/mi', $body, $matches) === 1) {
            return Str::limit(trim($matches[1]), 120, '');
        }

        return $this->fallbackName($url).' events';
    }

    private function fallbackName(string $url): string
    {
        $host = preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
        $name = Str::headline((string) Str::before($host, '.'));

        return $name !== '' ? $name : 'New source';
    }

    private function resolveUrl(string $href, string $baseUrl): ?string
    {
        if (str_starts_with($href, 'webcal://')) {
            $href = 'https://'.substr($href, 9);
        }

        if (filter_var($href, FILTER_VALIDATE_URL)) {
            return $href;
        }

        if (str_starts_with($href, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$href;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (! is_string($scheme) || ! is_string($host)) {
            return null;
        }

        $port = parse_url($baseUrl, PHP_URL_PORT);
        $origin = $scheme.'://'.$host.($port ? ':'.$port : '');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = (string) parse_url($baseUrl, PHP_URL_PATH);
        $directoryPath = str_ends_with($path, '/') ? $path : dirname($path);
        $directory = rtrim(str_replace('\\', '/', $directoryPath), '/.');

        return $origin.($directory !== '' ? '/'.ltrim($directory, '/') : '').'/'.ltrim($href, '/');
    }
}
