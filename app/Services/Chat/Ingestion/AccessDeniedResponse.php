<?php

namespace App\Services\Chat\Ingestion;

class AccessDeniedResponse
{
    public static function describe(int $status, string $body, ?string $server = null): ?string
    {
        if ($status !== 403) {
            return null;
        }

        // Only retain a bounded, validated support reference, never the remote
        // error page itself (which can contain request data or arbitrary HTML).
        $text = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', substr($body, 0, 8192)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $isAkamai = strcasecmp(trim((string) $server), 'AkamaiGHost') === 0
            || str_contains(strtolower($text), 'https://errors.edgesuite.net/');

        if (! $isAkamai) {
            return null;
        }

        $message = 'The source website’s Akamai security service denied the request (HTTP 403).';

        if (preg_match('/\bReference\s*#\s*([0-9]{1,3}\.[a-f0-9]{1,32}\.[0-9]{1,20}\.[a-f0-9]{1,32})(?![a-z0-9.])/i', $text, $matches)) {
            $message .= ' Reference: '.$matches[1].'.';
        }

        return $message.' Ask the website operator to review crawler access.';
    }
}
