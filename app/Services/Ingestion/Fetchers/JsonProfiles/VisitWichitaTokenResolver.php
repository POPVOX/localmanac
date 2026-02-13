<?php

namespace App\Services\Ingestion\Fetchers\JsonProfiles;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Throwable;

class VisitWichitaTokenResolver
{
    /**
     * @return array{token: string|null, error: string|null, request_url: string|null}
     */
    public function resolve(string $tokenSourceUrl): array
    {
        $tokenEndpointResult = $this->resolveFromTokenEndpoint($tokenSourceUrl);

        if (($tokenEndpointResult['token'] ?? null) !== null) {
            return $tokenEndpointResult;
        }

        $tokenEndpointError = trim((string) ($tokenEndpointResult['error'] ?? ''));
        $script = (string) config('services.visit_wichita.token_resolver_script');

        if ($script === '' || ! file_exists($script)) {
            $error = "Visit Wichita token refresh requires resolver script at [{$script}].";

            if ($tokenEndpointError !== '') {
                $error = "Visit Wichita token endpoint lookup failed: {$tokenEndpointError} {$error}";
            }

            return [
                'token' => null,
                'error' => $error,
                'request_url' => null,
            ];
        }

        $command = trim((string) config('services.visit_wichita.token_resolver_command', 'node'));
        $timeoutMs = (int) config('services.visit_wichita.token_resolver_timeout', 30000);
        $timeoutSeconds = max(1, (int) ceil($timeoutMs / 1000) + 5);

        $process = new Process([$command !== '' ? $command : 'node', $script, $tokenSourceUrl]);
        $process->setTimeout($timeoutSeconds);
        $process->setEnv([
            'VISIT_WICHITA_TOKEN_RESOLVER_TIMEOUT' => (string) $timeoutMs,
            'VISIT_WICHITA_TOKEN_ENDPOINT' => '/includes/rest_v2/plugins_events_events_by_date/find/',
            'VISIT_WICHITA_TOKEN_ENDPOINT_FALLBACK' => (string) config('services.visit_wichita.token_resolver_endpoint', '/plugins/core/get_simple_token/'),
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
            $error = $this->normalizeErrorMessage($fallback, 'Visit Wichita token resolver failed.');

            if ($tokenEndpointError !== '') {
                $error = "Visit Wichita token endpoint lookup failed: {$tokenEndpointError} {$error}";
            }

            return [
                'token' => null,
                'error' => $error,
                'request_url' => null,
            ];
        }

        if ($output === '') {
            return [
                'token' => null,
                'error' => $tokenEndpointError !== ''
                    ? "Visit Wichita token endpoint lookup failed: {$tokenEndpointError} Visit Wichita token resolver returned empty output."
                    : 'Visit Wichita token resolver returned empty output.',
                'request_url' => null,
            ];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return [
                'token' => null,
                'error' => $tokenEndpointError !== ''
                    ? "Visit Wichita token endpoint lookup failed: {$tokenEndpointError} Visit Wichita token resolver returned invalid JSON output."
                    : 'Visit Wichita token resolver returned invalid JSON output.',
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
        $error = $this->normalizeErrorMessage($error, 'Visit Wichita token resolver did not find a token.');

        if ($tokenEndpointError !== '') {
            $error = "Visit Wichita token endpoint lookup failed: {$tokenEndpointError} {$error}";
        }

        return [
            'token' => null,
            'error' => $error,
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

    /**
     * @return array{token: string|null, error: string|null, request_url: string|null}
     */
    private function resolveFromTokenEndpoint(string $tokenSourceUrl): array
    {
        $endpoint = $this->buildTokenEndpointUrl($tokenSourceUrl);
        $timeoutMs = (int) config('services.visit_wichita.token_resolver_timeout', 30000);
        $timeoutSeconds = max(1, min(15, (int) ceil($timeoutMs / 1000)));

        try {
            $response = Http::timeout($timeoutSeconds)
                ->retry(1, 200, throw: false)
                ->withHeaders([
                    'User-Agent' => (string) config('chat.user_agent', 'LocalmanacBot/1.0'),
                ])
                ->get($endpoint);
        } catch (Throwable $exception) {
            return [
                'token' => null,
                'error' => $exception->getMessage(),
                'request_url' => null,
            ];
        }

        if (! $response->successful()) {
            return [
                'token' => null,
                'error' => "Token endpoint returned status {$response->status()}.",
                'request_url' => null,
            ];
        }

        $token = trim($response->body());

        if ($this->looksLikeToken($token)) {
            return [
                'token' => strtolower($token),
                'error' => null,
                'request_url' => null,
            ];
        }

        return [
            'token' => null,
            'error' => 'Token endpoint response was not a valid token.',
            'request_url' => null,
        ];
    }

    private function buildTokenEndpointUrl(string $tokenSourceUrl): string
    {
        $configured = trim((string) config('services.visit_wichita.token_resolver_endpoint', '/plugins/core/get_simple_token/'));

        if ($configured === '') {
            $configured = '/plugins/core/get_simple_token/';
        }

        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return $configured;
        }

        $parts = parse_url($tokenSourceUrl);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'www.visitwichita.com';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = '/'.ltrim($configured, '/');

        return "{$scheme}://{$host}{$port}{$path}";
    }

    private function looksLikeToken(string $value): bool
    {
        return preg_match('/^[a-f0-9]{32}$/i', $value) === 1;
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
