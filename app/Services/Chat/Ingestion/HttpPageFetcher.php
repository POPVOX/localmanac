<?php

namespace App\Services\Chat\Ingestion;

use App\Services\Ingestion\DownloadPolicy;
use App\Services\Ingestion\DownloadRejected;
use Illuminate\Support\Facades\Http;

class HttpPageFetcher
{
    public ?string $lastFailure = null;

    /**
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    public function fetch(string $url): ?array
    {
        $this->lastFailure = null;
        $policy = app(DownloadPolicy::class);
        if ($policy->isMediaUrl($url)) {
            throw new DownloadRejected('Media URL skipped.');
        }

        try {
            $response = Http::withHeaders([
                'User-Agent' => (string) config('chat.user_agent', 'LocalmanacBot/1.0'),
            ])
                ->withOptions($policy->options())
                ->timeout((int) config('chat.fetch_timeout', 12))
                ->retry((int) config('chat.fetch_retries', 1), 250,
                    fn (\Exception $exception): bool => $policy->rejectionFrom($exception) === null)
                ->get($url);
        } catch (\Throwable $exception) {
            if ($rejection = $policy->rejectionFrom($exception)) {
                throw $rejection;
            }

            $this->lastFailure = $exception instanceof \Illuminate\Http\Client\RequestException
                ? $this->statusFailure($exception->response->status(), $exception->response->body(), $exception->response->header('Server'))
                : 'The direct request failed or timed out before a response was received.';

            return null;
        }

        if (! $response->successful()) {
            $this->lastFailure = $this->statusFailure($response->status(), $response->body(), $response->header('Server'));

            return null;
        }

        $policy->assertHeaders($response->toPsrResponse());
        $policy->assertSize(strlen($response->body()));

        return [
            'url' => $url,
            'status_code' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body' => (string) $response->body(),
            'renderer' => 'http',
        ];
    }

    private function statusFailure(int $status, string $body, ?string $server): string
    {
        if ($denied = AccessDeniedResponse::describe($status, $body, $server)) {
            return $denied;
        }

        return in_array($status, [401, 403, 429], true)
            ? "The site denied or limited the direct request (HTTP {$status})."
            : "The site returned HTTP {$status} to the direct request.";
    }
}
