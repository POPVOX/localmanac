<?php

namespace App\Services\Ingestion\Fetchers\HtmlProfiles;

class HtmlProfileRegistry
{
    /**
     * @param  array<int, HtmlProfile>  $profiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly HtmlProfile $fallback,
    ) {}

    public function resolve(?string $profileName): HtmlProfile
    {
        foreach ($this->profiles as $profile) {
            if ($profile->supports($profileName)) {
                return $profile;
            }
        }

        return $this->fallback;
    }
}
