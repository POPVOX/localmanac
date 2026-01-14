<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;

interface HtmlProfile
{
    public function supports(?string $profileName): bool;

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, EventDTO>
     */
    public function fetchAndMap(EventSource $source, array $config, string $timezone): array;
}
