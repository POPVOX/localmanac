<?php

namespace App\Services\Chat;

class ChatSourceGuard
{
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

        if (in_array($normalizedTitle, [
            'email protection | cloudflare',
            'attention required! | cloudflare',
            'just a moment...',
        ], true)) {
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

        return ! in_array(mb_strtolower(trim((string) $title)), [
            'email protection | cloudflare',
            'attention required! | cloudflare',
            'just a moment...',
        ], true);
    }
}
