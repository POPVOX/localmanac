<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;

interface JsonProfile
{
    public function supports(?string $profileName): bool;

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{request_url: string, payload: mixed}>
     */
    public function fetchPayloads(EventSource $source, array $config, string $timezone): array;

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    public function mapToEvents(mixed $payload, EventSource $source, array $config, string $timezone, string $requestUrl): array;
}
