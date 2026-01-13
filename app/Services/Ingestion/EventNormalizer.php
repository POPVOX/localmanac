<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Str;

class EventNormalizer
{
    public function normalizeTitle(string $title): string
    {
        $normalized = trim($title);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? '';

        return Str::lower($normalized);
    }

    public function normalizeLocation(?string $name, ?string $address = null): string
    {
        $parts = array_filter([
            $name ? trim($name) : null,
            $address ? trim($address) : null,
        ]);

        if ($parts === []) {
            return '';
        }

        return $this->normalizeTitle(implode(' ', $parts));
    }

    public function normalizeUrl(?string $url, ?string $baseUrl = null): ?string
    {
        if (! $url) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (Str::startsWith($url, ['http://', 'https://'])) {
            return $url;
        }

        if (Str::startsWith($url, '//')) {
            $scheme = $baseUrl ? (parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https') : 'https';

            return $scheme.':'.$url;
        }

        if (! $baseUrl) {
            return $url;
        }

        if (Str::startsWith($url, '/')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($baseUrl, PHP_URL_HOST);
            $port = parse_url($baseUrl, PHP_URL_PORT);

            if (! $host) {
                return $url;
            }

            $port = $port ? ':'.$port : '';

            return "{$scheme}://{$host}{$port}{$url}";
        }

        $base = parse_url($baseUrl);

        if (! $base || ! isset($base['scheme'], $base['host'])) {
            return $url;
        }

        $scheme = $base['scheme'];
        $host = $base['host'];
        $port = isset($base['port']) ? ':'.$base['port'] : '';
        $path = $base['path'] ?? '/';
        $directory = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
        $directory = Str::finish($directory, '/');
        $directory = Str::start($directory, '/');

        return "{$scheme}://{$host}{$port}{$directory}{$url}";
    }
}
