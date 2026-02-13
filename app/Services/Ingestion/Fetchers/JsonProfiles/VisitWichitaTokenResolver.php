<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use Symfony\Component\Process\Process;
use Throwable;

class VisitWichitaTokenResolver
{
    /**
     * @return array{token: string|null, error: string|null, request_url: string|null}
     */
    public function resolve(string $tokenSourceUrl): array
    {
        $script = (string) config('services.visit_wichita.token_resolver_script');

        if ($script === '' || ! file_exists($script)) {
            return [
                'token' => null,
                'error' => "Visit Wichita token refresh requires resolver script at [{$script}].",
                'request_url' => null,
            ];
        }

        $command = trim((string) config('services.visit_wichita.token_resolver_command', 'node'));
        $timeoutMs = (int) config('services.visit_wichita.token_resolver_timeout', 30000);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));

        $process = new Process([$command !== '' ? $command : 'node', $script, $tokenSourceUrl]);
        $process->setTimeout($timeoutSeconds);
        $process->setEnv([
            'VISIT_WICHITA_TOKEN_RESOLVER_TIMEOUT' => (string) $timeoutMs,
            'VISIT_WICHITA_TOKEN_ENDPOINT' => '/includes/rest_v2/plugins_events_events_by_date/find/',
            'PLAYWRIGHT_USER_AGENT' => (string) config('chat.user_agent', 'LocalmanacBot/1.0'),
        ]);

        try {
            $process->run();
        } catch (Throwable $exception) {
            return [
                'token' => null,
                'error' => 'Visit Wichita token resolver could not execute: '.$exception->getMessage(),
                'request_url' => null,
            ];
        }

        $output = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            $fallback = $errorOutput !== '' ? $errorOutput : $output;

            return [
                'token' => null,
                'error' => $this->normalizeErrorMessage($fallback, 'Visit Wichita token resolver failed.'),
                'request_url' => null,
            ];
        }

        if ($output === '') {
            return [
                'token' => null,
                'error' => 'Visit Wichita token resolver returned empty output.',
                'request_url' => null,
            ];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return [
                'token' => null,
                'error' => 'Visit Wichita token resolver returned invalid JSON output.',
                'request_url' => null,
            ];
        }

        $token = trim((string) ($decoded['token'] ?? ''));
        $requestUrl = trim((string) ($decoded['request_url'] ?? ''));

        if ($token === '' && $requestUrl !== '') {
            $token = $this->extractTokenFromRequestUrl($requestUrl) ?? '';
        }

        if ($token !== '') {
            return [
                'token' => $token,
                'error' => null,
                'request_url' => $requestUrl !== '' ? $requestUrl : null,
            ];
        }

        $error = trim((string) ($decoded['error'] ?? ''));

        return [
            'token' => null,
            'error' => $this->normalizeErrorMessage($error, 'Visit Wichita token resolver did not find a token.'),
            'request_url' => $requestUrl !== '' ? $requestUrl : null,
        ];
    }

    public function extractTokenFromRequestUrl(string $requestUrl): ?string
    {
        $query = parse_url($requestUrl, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        $params = [];
        parse_str($query, $params);

        if (! array_key_exists('token', $params)) {
            return null;
        }

        $token = $params['token'];

        if (is_array($token)) {
            $token = $token[0] ?? '';
        }

        $token = trim((string) $token);

        return $token !== '' ? $token : null;
    }

    private function normalizeErrorMessage(string $value, string $fallback): string
    {
        $value = trim($value);

        if ($value === '') {
            return $fallback;
        }

        $decoded = json_decode($value, true);

        if (is_array($decoded)) {
            $candidate = trim((string) ($decoded['error'] ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return $value;
    }
}
