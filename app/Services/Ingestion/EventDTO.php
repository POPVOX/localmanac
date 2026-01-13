<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Carbon;

class EventDTO
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $title,
        public ?Carbon $startsAt,
        public ?Carbon $endsAt,
        public bool $allDay,
        public ?string $locationName,
        public ?string $locationAddress,
        public ?string $description,
        public ?string $eventUrl,
        public ?string $externalId,
        public ?string $sourceUrl,
        public ?string $sourceHash = null,
        public array $rawPayload = [],
    ) {}

    /**
     * @return array{
     *   title: string,
     *   starts_at: ?Carbon,
     *   ends_at: ?Carbon,
     *   all_day: bool,
     *   location_name: ?string,
     *   location_address: ?string,
     *   description: ?string,
     *   event_url: ?string,
     *   external_id: ?string,
     *   source_url: ?string,
     *   source_hash: ?string,
     *   raw_payload: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'all_day' => $this->allDay,
            'location_name' => $this->locationName,
            'location_address' => $this->locationAddress,
            'description' => $this->description,
            'event_url' => $this->eventUrl,
            'external_id' => $this->externalId,
            'source_url' => $this->sourceUrl,
            'source_hash' => $this->sourceHash,
            'raw_payload' => $this->rawPayload,
        ];
    }
}
