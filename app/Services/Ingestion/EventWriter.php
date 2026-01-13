<?php

namespace App\Services\Ingestion;

use App\Models\Event;
use App\Models\EventSource;
use App\Models\EventSourceItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EventWriter
{
    public function __construct(private readonly EventNormalizer $normalizer) {}

    public function write(EventSource $source, EventDTO $event): Event
    {
        $cityId = $source->city_id;
        $title = trim($event->title);
        $startsAt = $event->startsAt;

        if (! $cityId) {
            throw new InvalidArgumentException('EventSource is missing city_id');
        }

        if ($title === '') {
            throw new InvalidArgumentException('Event title is required');
        }

        if (! $startsAt) {
            throw new InvalidArgumentException('Event starts_at is required');
        }

        $locationName = $this->sanitizeLocation($event->locationName);
        $locationAddress = $this->sanitizeLocation($event->locationAddress);

        $normalizedTitle = $this->normalizer->normalizeTitle($title);
        $normalizedLocation = $this->normalizer->normalizeLocation($locationName, $locationAddress);
        $startsAtUtc = $startsAt->copy()->utc()->format('Y-m-d H:i:s');
        $sourceHash = $this->resolveSourceHash($event, $cityId, $normalizedTitle, $normalizedLocation, $startsAtUtc);

        $eventUrl = $this->normalizer->normalizeUrl($event->eventUrl, $source->source_url);
        $sourceUrl = $this->normalizer->normalizeUrl(
            $event->sourceUrl ?? $eventUrl ?? $source->source_url,
            $source->source_url
        );

        return DB::transaction(function () use ($source, $event, $cityId, $title, $sourceHash, $eventUrl, $sourceUrl, $locationName, $locationAddress) {
            $model = Event::updateOrCreate(
                ['source_hash' => $sourceHash],
                [
                    'city_id' => $cityId,
                    'title' => $title,
                    'starts_at' => $event->startsAt,
                    'ends_at' => $event->endsAt,
                    'all_day' => $event->allDay,
                    'location_name' => $locationName,
                    'location_address' => $locationAddress,
                    'description' => $event->description,
                    'event_url' => $eventUrl,
                ]
            );

            EventSourceItem::updateOrCreate(
                [
                    'event_id' => $model->id,
                    'event_source_id' => $source->id,
                    'source_url' => $sourceUrl,
                    'external_id' => $event->externalId,
                ],
                [
                    'raw_payload' => $event->rawPayload,
                    'fetched_at' => now(),
                ]
            );

            return $model;
        });
    }

    private function resolveSourceHash(
        EventDTO $event,
        int $cityId,
        string $normalizedTitle,
        string $normalizedLocation,
        string $startsAtUtc,
    ): string {
        $sourceHash = $event->sourceHash;

        if ($sourceHash !== null) {
            $sourceHash = trim($sourceHash);

            if ($sourceHash !== '') {
                return $this->normalizeSourceHash($sourceHash);
            }
        }

        return sha1($cityId.'|'.$normalizedTitle.'|'.$startsAtUtc.'|'.$normalizedLocation);
    }

    private function normalizeSourceHash(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return sha1($value);
        }

        if (strlen($value) === 40 && ctype_xdigit($value)) {
            return strtolower($value);
        }

        return sha1($value);
    }

    private function sanitizeLocation(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $cleaned = str_replace('\\', '', $value);
        $cleaned = trim(preg_replace('/\s+/', ' ', $cleaned) ?? '');

        return $cleaned !== '' ? $cleaned : null;
    }
}
