<?php

namespace App\Services\Chat;

class ChatSourceGuard
{
    public const ERROR_TITLES = [
        'email protection | cloudflare', 'attention required! | cloudflare', 'just a moment...',
        '404 not found', '404 - not found', '404', 'error 404', 'page not found', 'not found',
        '403 forbidden', '403', 'forbidden', 'access denied',
        '500 internal server error', 'internal server error',
        '502 bad gateway', 'bad gateway', '503 service unavailable', 'service unavailable',
        '504 gateway timeout', 'gateway timeout',
    ];

    public function isBlockedUrl(?string $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));

        if (str_contains($path, '/cdn-cgi/')) {
            return true;
        }

        return in_array($host, ['challenges.cloudflare.com'], true);
    }

    public function isBlockedPage(?string $url, ?string $canonicalUrl = null, ?string $title = null, string $content = ''): bool
    {
        if ($this->isBlockedUrl($url) || $this->isBlockedUrl($canonicalUrl)) {
            return true;
        }

        $normalizedTitle = mb_strtolower(trim((string) $title));
        $normalizedContent = mb_strtolower(trim($content));

        if (in_array($normalizedTitle, self::ERROR_TITLES, true)) {
            return true;
        }

        if (mb_strlen($normalizedContent) < 1000
            && preg_match('/^(?:404\\s+not found|403\\s+forbidden|502\\s+bad gateway|503\\s+service unavailable)\\b/', $normalizedContent)) {
            return true;
        }

        return str_contains($normalizedContent, 'the website from which you got to this page is protected by cloudflare')
            || str_contains($normalizedContent, 'email addresses on that page have been hidden');
    }

    public function isAllowedCitation(?string $url, ?string $title = null): bool
    {
        if ($this->isBlockedUrl($url)) {
            return false;
        }

        return ! $this->isBlockedPage($url, null, $title);
    }
}
