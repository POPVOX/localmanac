<?php

namespace App\Services\Chat\Ingestion;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PlaywrightPageFetcher
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{url: string, status_code: int, content_type: string|null, body: string, renderer: string}|null
     */
    public function fetch(string $url, array $options = []): ?array
    {
        $script = (string) config('chat.playwright_script');

        if ($script === '' || ! file_exists($script)) {
            return null;
        }

        $nodeBinary = $this->resolveNodeBinary();

        if ($nodeBinary === null) {
            Log::warning('Playwright fetch skipped because Node.js binary could not be resolved.', [
                'url' => $url,
                'configured_node_binary' => (string) config('chat.playwright_node_binary', 'node'),
            ]);

            return null;
        }

        $timeoutMs = $this->resolveTimeoutMs($options);
        $waitSelector = $this->resolveWaitSelector($options);
        $userAgent = $this->resolveUserAgent($options);
        $storageStatePath = $this->resolveStorageStatePath($options);
        $proxy = $this->resolveProxy($options);
        $refreshOnBlocked = $this->resolveRefreshOnBlocked($options);
        $refreshAttempts = $this->resolveRefreshAttempts($options);
        $autoScroll = $this->resolveAutoScroll($options);
        $maxScrollSteps = $this->resolveMaxScrollSteps($options);
        $scrollPauseMs = $this->resolveScrollPauseMs($options);
        $timeoutSeconds = $this->resolveProcessTimeoutSeconds(
            $timeoutMs,
            $refreshOnBlocked ? $refreshAttempts : 0,
            $autoScroll ? $maxScrollSteps : 0,
            $autoScroll ? $scrollPauseMs : 0,
        );

        $process = new Process([
            $nodeBinary,
            $script,
            $url,
        ]);

        $process->setTimeout($timeoutSeconds);
        $processEnv = [
            'PLAYWRIGHT_TIMEOUT' => (string) $timeoutMs,
            'PLAYWRIGHT_WAIT_SELECTOR' => $waitSelector,
            'PLAYWRIGHT_USER_AGENT' => $userAgent,
            'PLAYWRIGHT_REFRESH_ON_BLOCKED' => $refreshOnBlocked ? '1' : '0',
            'PLAYWRIGHT_REFRESH_ATTEMPTS' => (string) $refreshAttempts,
            'PLAYWRIGHT_AUTO_SCROLL' => $autoScroll ? '1' : '0',
            'PLAYWRIGHT_MAX_SCROLL_STEPS' => (string) $maxScrollSteps,
            'PLAYWRIGHT_SCROLL_PAUSE_MS' => (string) $scrollPauseMs,
        ];

        if ($storageStatePath !== null) {
            $processEnv['PLAYWRIGHT_STORAGE_STATE_PATH'] = $storageStatePath;
        }

        if ($proxy !== null) {
            $processEnv['PLAYWRIGHT_PROXY_SERVER'] = $proxy['server'];

            if (array_key_exists('username', $proxy) && $proxy['username'] !== null) {
                $processEnv['PLAYWRIGHT_PROXY_USERNAME'] = $proxy['username'];
            }

            if (array_key_exists('password', $proxy) && $proxy['password'] !== null) {
                $processEnv['PLAYWRIGHT_PROXY_PASSWORD'] = $proxy['password'];
            }

            if (array_key_exists('bypass', $proxy) && $proxy['bypass'] !== null) {
                $processEnv['PLAYWRIGHT_PROXY_BYPASS'] = $proxy['bypass'];
            }
        }

        if (str_contains($nodeBinary, DIRECTORY_SEPARATOR)) {
            $currentPath = (string) getenv('PATH');
            $nodeDirectory = dirname($nodeBinary);
            $processEnv['PATH'] = $nodeDirectory.($currentPath !== '' ? ':'.$currentPath : '');
        }

        $process->setEnv($processEnv);

        try {
            $process->run();
        } catch (ProcessTimedOutException $exception) {
            Log::warning('Playwright fetch process timed out.', [
                'url' => $url,
                'node_binary' => $nodeBinary,
                'storage_state_path' => $storageStatePath,
                'proxy_server' => $proxy['server'] ?? null,
                'refresh_on_blocked' => $refreshOnBlocked,
                'refresh_attempts' => $refreshAttempts,
                'timeout_seconds' => $timeoutSeconds,
                'error_output' => trim($process->getErrorOutput()),
            ]);

            return null;
        }

        if (! $process->isSuccessful()) {
            Log::warning('Playwright fetch process failed.', [
                'url' => $url,
                'node_binary' => $nodeBinary,
                'storage_state_path' => $storageStatePath,
                'proxy_server' => $proxy['server'] ?? null,
                'refresh_on_blocked' => $refreshOnBlocked,
                'refresh_attempts' => $refreshAttempts,
                'exit_code' => $process->getExitCode(),
                'error_output' => trim($process->getErrorOutput()),
            ]);

            return null;
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            Log::warning('Playwright fetch process returned empty output.', [
                'url' => $url,
                'node_binary' => $nodeBinary,
            ]);

            return null;
        }

        $payload = json_decode($output, true);

        if (! is_array($payload) || empty($payload['html'])) {
            Log::warning('Playwright fetch process returned invalid payload.', [
                'url' => $url,
                'node_binary' => $nodeBinary,
            ]);

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

    private function resolveNodeBinary(): ?string
    {
        $configuredBinary = trim((string) config('chat.playwright_node_binary', 'node'));

        $candidates = array_values(array_unique(array_filter([
            $configuredBinary,
            'node',
            '/opt/homebrew/bin/node',
            '/usr/local/bin/node',
            '/usr/bin/node',
            ...$this->nvmNodeCandidates(),
        ], fn (mixed $candidate): bool => is_string($candidate) && trim($candidate) !== '')));

        foreach ($candidates as $candidate) {
            $trimmed = trim($candidate);

            if (str_contains($trimmed, DIRECTORY_SEPARATOR)) {
                if (is_file($trimmed) && is_executable($trimmed)) {
                    return $trimmed;
                }

                continue;
            }

            $resolved = $this->resolveCommandPath($trimmed);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function nvmNodeCandidates(): array
    {
        $homeDirectory = getenv('HOME');

        if (! is_string($homeDirectory) || trim($homeDirectory) === '') {
            return [];
        }

        $matches = glob(rtrim($homeDirectory, '/').'/.nvm/versions/node/*/bin/node');

        if (! is_array($matches) || $matches === []) {
            return [];
        }

        rsort($matches);

        return array_values($matches);
    }

    private function resolveCommandPath(string $command): ?string
    {
        $whichBinary = '/usr/bin/which';

        if (! is_file($whichBinary) || ! is_executable($whichBinary)) {
            return null;
        }

        $process = new Process([$whichBinary, $command]);
        $process->setTimeout(2);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $resolvedPath = trim($process->getOutput());

        if ($resolvedPath === '' || ! is_file($resolvedPath) || ! is_executable($resolvedPath)) {
            return null;
        }

        return $resolvedPath;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveTimeoutMs(array $options): int
    {
        $timeout = $options['timeout_ms'] ?? config('chat.playwright_timeout', 30000);

        if (! is_numeric($timeout)) {
            return 30000;
        }

        return max(1000, (int) $timeout);
    }

    private function resolveProcessTimeoutSeconds(int $timeoutMs, int $refreshAttempts, int $maxScrollSteps, int $scrollPauseMs): int
    {
        $baseTimeoutSeconds = max(1, (int) ceil($timeoutMs / 1000));
        $attempts = max(0, $refreshAttempts);
        $scrollSteps = max(0, $maxScrollSteps);
        $scrollPause = max(0, $scrollPauseMs);

        // Script flow can include: initial navigate + (warmup + navigate + reload) per refresh attempt.
        $maxNavigationWindows = 1 + ($attempts * 3);
        $scrollWindows = 1 + $attempts;
        $estimatedScrollMs = $scrollSteps * $scrollPause * $scrollWindows;
        $estimatedMaxMs = ($timeoutMs * $maxNavigationWindows) + $estimatedScrollMs + 15000;
        $estimatedSeconds = max($baseTimeoutSeconds, (int) ceil($estimatedMaxMs / 1000));

        // Keep the process bound to avoid stalled executions.
        return min(300, $estimatedSeconds);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveWaitSelector(array $options): string
    {
        $selector = $options['wait_selector'] ?? config('chat.playwright_wait_selector', '');

        if (! is_string($selector)) {
            return '';
        }

        return trim($selector);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveUserAgent(array $options): string
    {
        $userAgent = $options['user_agent']
            ?? config('chat.playwright_user_agent', config('chat.user_agent', 'LocalmanacBot/1.0'));

        if (! is_string($userAgent) || trim($userAgent) === '') {
            return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
        }

        return trim($userAgent);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveStorageStatePath(array $options): ?string
    {
        $rawPath = $options['storage_state_path'] ?? config('chat.playwright_storage_state_path');

        if (! is_string($rawPath) || trim($rawPath) === '') {
            return null;
        }

        $trimmed = trim($rawPath);

        if (str_starts_with($trimmed, DIRECTORY_SEPARATOR)) {
            return $trimmed;
        }

        return base_path($trimmed);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{server: string, username?: string|null, password?: string|null, bypass?: string|null}|null
     */
    private function resolveProxy(array $options): ?array
    {
        $proxyOption = $options['proxy'] ?? null;
        $server = null;
        $username = null;
        $password = null;
        $bypass = null;

        if (is_array($proxyOption)) {
            $serverValue = $proxyOption['server'] ?? null;

            if (is_string($serverValue) && trim($serverValue) !== '') {
                $server = trim($serverValue);
            }

            $usernameValue = $proxyOption['username'] ?? null;
            $passwordValue = $proxyOption['password'] ?? null;
            $bypassValue = $proxyOption['bypass'] ?? null;

            if (is_string($usernameValue) && trim($usernameValue) !== '') {
                $username = trim($usernameValue);
            }

            if (is_string($passwordValue) && trim($passwordValue) !== '') {
                $password = trim($passwordValue);
            }

            if (is_string($bypassValue) && trim($bypassValue) !== '') {
                $bypass = trim($bypassValue);
            }
        }

        if ($server === null) {
            $serverConfig = config('chat.playwright_proxy_server');

            if (is_string($serverConfig) && trim($serverConfig) !== '') {
                $server = trim($serverConfig);
            }

            if ($username === null) {
                $usernameConfig = config('chat.playwright_proxy_username');
                if (is_string($usernameConfig) && trim($usernameConfig) !== '') {
                    $username = trim($usernameConfig);
                }
            }

            if ($password === null) {
                $passwordConfig = config('chat.playwright_proxy_password');
                if (is_string($passwordConfig) && trim($passwordConfig) !== '') {
                    $password = trim($passwordConfig);
                }
            }

            if ($bypass === null) {
                $bypassConfig = config('chat.playwright_proxy_bypass');
                if (is_string($bypassConfig) && trim($bypassConfig) !== '') {
                    $bypass = trim($bypassConfig);
                }
            }
        }

        if ($server === null) {
            return null;
        }

        return [
            'server' => $server,
            'username' => $username,
            'password' => $password,
            'bypass' => $bypass,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveRefreshOnBlocked(array $options): bool
    {
        $value = $options['refresh_on_blocked'] ?? config('chat.playwright_refresh_on_blocked', true);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = mb_strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveRefreshAttempts(array $options): int
    {
        $value = $options['refresh_attempts'] ?? config('chat.playwright_refresh_attempts', 1);

        if (! is_numeric($value)) {
            return 1;
        }

        return max(0, min(5, (int) $value));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveAutoScroll(array $options): bool
    {
        $value = $options['auto_scroll'] ?? config('chat.playwright_auto_scroll', false);

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveMaxScrollSteps(array $options): int
    {
        $value = $options['max_scroll_steps'] ?? config('chat.playwright_max_scroll_steps', 8);

        if (! is_numeric($value)) {
            return 8;
        }

        return max(0, min(50, (int) $value));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function resolveScrollPauseMs(array $options): int
    {
        $value = $options['scroll_pause_ms'] ?? config('chat.playwright_scroll_pause_ms', 500);

        if (! is_numeric($value)) {
            return 500;
        }

        return max(100, min(10000, (int) $value));
    }
}
