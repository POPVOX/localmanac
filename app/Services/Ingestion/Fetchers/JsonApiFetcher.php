<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\EventSource;
use App\Services\Ingestion\CalendarDateParser;
use App\Services\Ingestion\EventDTO;
use App\Services\Ingestion\EventNormalizer;
use App\Services\Ingestion\Fetchers\JsonProfiles\Century2CalendarProfile;
use App\Services\Ingestion\Fetchers\JsonProfiles\GenericJsonProfile;
use App\Services\Ingestion\Fetchers\JsonProfiles\JsonProfileRegistry;
use App\Services\Ingestion\Fetchers\JsonProfiles\VisitWichitaSimpleviewProfile;
use App\Services\Ingestion\Fetchers\JsonProfiles\WichitaLibnetLibcalProfile;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class JsonApiFetcher implements EventSourceFetcher
{
    public function __construct(
        private readonly CalendarDateParser $dateParser,
        private readonly EventNormalizer $normalizer,
        private readonly ?JsonProfileRegistry $profileRegistry = null,
    ) {}

    /**
     * @return array<int, EventDTO>
     */
    public function fetch(EventSource $source): array
    {
        if (! in_array($source->source_type, ['json', 'json_api'], true)) {
            throw new InvalidArgumentException('EventSource type must be json or json_api');
        }

        $sourceUrl = $source->source_url;

        if (! $sourceUrl) {
            throw new InvalidArgumentException('EventSource source_url is required');
        }

        $sourceConfig = $source->config ?? [];
        $config = Arr::get($sourceConfig, 'json', $sourceConfig);
        $profile = Arr::get($sourceConfig, 'profile') ?? Arr::get($config, 'profile');

        $timezone = Arr::get($config, 'timezone') ?? $source->city?->timezone ?? 'UTC';
        $registry = $this->profileRegistry ?? $this->buildProfileRegistry();
        $handler = $registry->resolve($profile);

        $payloads = $handler->fetchPayloads($source, $config, $timezone);
        $results = [];

        foreach ($payloads as $payload) {
            $results = array_merge(
                $results,
                $handler->mapToEvents($payload['payload'], $source, $config, $timezone, $payload['request_url'])
            );
        }

        return $results;
    }

    private function buildProfileRegistry(): JsonProfileRegistry
    {
        return new JsonProfileRegistry(
            [
                new VisitWichitaSimpleviewProfile($this->dateParser, $this->normalizer),
                new WichitaLibnetLibcalProfile($this->dateParser, $this->normalizer),
                new Century2CalendarProfile($this->dateParser, $this->normalizer),
            ],
            new GenericJsonProfile($this->dateParser, $this->normalizer),
        );
    }
}
