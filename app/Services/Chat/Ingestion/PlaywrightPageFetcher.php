<?php

namespace App\Services\Chat\Ingestion;

use Symfony\Component\Process\Process;

class PlaywrightPageFetcher
{
    /**
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    public function fetch(string $url): ?array
    {
        $script = (string) config('chat.playwright_script');

        if ($script === '' || ! file_exists($script)) {
            return null;
        }

        $timeoutMs = (int) config('chat.playwright_timeout', 30000);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

        $process = new Process([
            'node',
            $script,
            $url,
        ]);

        $process->setTimeout($timeoutSeconds);
        $process->setEnv([
            'PLAYWRIGHT_TIMEOUT' => (string) $timeoutMs,
            'PLAYWRIGHT_WAIT_SELECTOR' => (string) config('chat.playwright_wait_selector', ''),
            'PLAYWRIGHT_USER_AGENT' => (string) config('chat.user_agent', 'LocalmanacBot/1.0'),
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            return null;
        }

        $payload = json_decode($output, true);

        if (! is_array($payload) || empty($payload['html'])) {
            return null;
        }

        return [
            'url' => (string) ($payload['url'] ?? $url),
            'status_code' => 200,
            'content_type' => 'text/html',
            'body' => (string) $payload['html'],
            'renderer' => 'playwright',
        ];
    }
}
