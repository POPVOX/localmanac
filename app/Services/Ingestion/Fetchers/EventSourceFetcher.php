<?php

namespace App\Services\Ingestion\Fetchers;

use App\Models\EventSource;
use App\Services\Ingestion\EventDTO;

interface EventSourceFetcher
{
    /**
     * @return array<int, EventDTO>
     */
    public function fetch(EventSource $source): array;
}
