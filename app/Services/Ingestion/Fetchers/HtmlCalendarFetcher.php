<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\Fetchers\HtmlProfiles\GenericHtmlListProfile;
use App\Services\Ingestion\Fetchers\HtmlProfiles\HtmlProfileRegistry;
use App\Services\Ingestion\Fetchers\HtmlProfiles\WichitaChamberEventsProfile;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class HtmlCalendarFetcher implements EventSourceFetcher
{
    public function __construct(
        private readonly CalendarDateParser $dateParser,
        private readonly EventNormalizer $normalizer,
        private readonly ?HtmlProfileRegistry $profileRegistry = null,
    ) {}

    /**
     * @return array<int, EventDTO>
     */
    public function fetch(EventSource $source): array
    {
        if ($source->source_type !== 'html') {
            throw new InvalidArgumentException('EventSource type must be html');
        }

        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $config = $source->config ?? [];
        $profile = Arr::get($config, 'profile');
        $timezone = Arr::get($config, 'timezone') ?? $source->city?->timezone ?? 'UTC';

        $registry = $this->profileRegistry ?? $this->buildProfileRegistry();
        $handler = $registry->resolve($profile);

        return $handler->fetchAndMap($source, $config, $timezone);
    }

    private function buildProfileRegistry(): HtmlProfileRegistry
    {
        return new HtmlProfileRegistry(
            [
                new WichitaChamberEventsProfile($this->dateParser, $this->normalizer),
            ],
            new GenericHtmlListProfile($this->dateParser, $this->normalizer),
        );
    }
}
