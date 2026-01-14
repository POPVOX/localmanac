<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

class JsonProfileRegistry
{
    /**
     * @param  array<int, JsonProfile>  $profiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly JsonProfile $fallback,
    ) {}

    public function resolve(?string $profileName): JsonProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->supports($profileName)) {
                return $profile;
            }
        }

        return $this->fallback;
    }
}
