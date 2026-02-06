<?php

namespace App\Services\Chat\Ingestion;

use Illuminate\Support\Facades\Http;

class HttpPageFetcher
{
    /**
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    public function fetch(string $url): ?array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('chat.user_agent', 'LocalmanacBot/1.0'),
            ])
                ->timeout((int) config('chat.fetch_timeout', 12))
                ->retry((int) config('chat.fetch_retries', 1), 250)
                ->get($url);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return [
            'url' => $url,
            'status_code' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body' => (string) $response->body(),
            'renderer' => 'http',
        ];
    }
}
