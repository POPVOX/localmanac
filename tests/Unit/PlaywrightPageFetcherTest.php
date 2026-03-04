<?php

use App\Services\Chat\Ingestion\PlaywrightPageFetcher;
use Illuminate\Support\Facades\Log;

uses(Tests\TestCase::class);

function makeExecutableScript(string $filename, string $contents): string
{
    $directory = storage_path('framework/testing/playwright-fetcher');

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $path = $directory.'/'.$filename;
    file_put_contents($path, $contents);
    chmod($path, 0755);

    return $path;
}

it('uses configured absolute node binary for playwright fetches', function () {
    $nodeStub = makeExecutableScript('node-stub-success.sh', <<<'SH'
#!/bin/sh
echo '{"url":"https://example.com/final","html":"<html><body>Rendered</body></html>"}'
SH);

    $scriptStub = makeExecutableScript('playwright-script-stub.mjs', <<<'JS'
// Placeholder script file required by PlaywrightPageFetcher.
JS);

    config()->set('chat.playwright_node_binary', $nodeStub);
    config()->set('chat.playwright_script', $scriptStub);
    config()->set('chat.playwright_timeout', 1000);

    $result = app(PlaywrightPageFetcher::class)->fetch('https://example.com/start');

    expect($result)->not->toBeNull()
        ->and($result['url'])->toBe('https://example.com/final')
        ->and($result['renderer'])->toBe('playwright')
        ->and($result['body'])->toContain('Rendered');
});

it('logs a warning when playwright process fails', function () {
    $nodeStub = makeExecutableScript('node-stub-failure.sh', <<<'SH'
#!/bin/sh
echo "failed to launch playwright" 1>&2
exit 1
SH);

    $scriptStub = makeExecutableScript('playwright-script-stub-failure.mjs', <<<'JS'
// Placeholder script file required by PlaywrightPageFetcher.
JS);

    config()->set('chat.playwright_node_binary', $nodeStub);
    config()->set('chat.playwright_script', $scriptStub);
    config()->set('chat.playwright_timeout', 1000);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Playwright fetch process failed.'
                && ($context['url'] ?? null) === 'https://example.com/start';
        });

    $result = app(PlaywrightPageFetcher::class)->fetch('https://example.com/start');

    expect($result)->toBeNull();
});

it('passes storage state and proxy options to playwright process', function () {
    $nodeStub = makeExecutableScript('node-stub-env.sh', <<<'SH'
#!/bin/sh
printf '{"url":"https://example.com/final","html":"<html><body>%s|%s|%s|%s|%s|%s|%s|%s|%s|%s|%s</body></html>"}' \
  "$PLAYWRIGHT_STORAGE_STATE_PATH" \
  "$PLAYWRIGHT_PROXY_SERVER" \
  "$PLAYWRIGHT_PROXY_USERNAME" \
  "$PLAYWRIGHT_PROXY_PASSWORD" \
  "$PLAYWRIGHT_PROXY_BYPASS" \
  "$PLAYWRIGHT_REFRESH_ON_BLOCKED" \
  "$PLAYWRIGHT_REFRESH_ATTEMPTS" \
  "$PLAYWRIGHT_USER_AGENT" \
  "$PLAYWRIGHT_AUTO_SCROLL" \
  "$PLAYWRIGHT_MAX_SCROLL_STEPS" \
  "$PLAYWRIGHT_SCROLL_PAUSE_MS"
SH);

    $scriptStub = makeExecutableScript('playwright-script-stub-env.mjs', <<<'JS'
// Placeholder script file required by PlaywrightPageFetcher.
JS);

    config()->set('chat.playwright_node_binary', $nodeStub);
    config()->set('chat.playwright_script', $scriptStub);
    config()->set('chat.playwright_timeout', 1000);

    $result = app(PlaywrightPageFetcher::class)->fetch('https://example.com/start', [
        'storage_state_path' => 'storage/app/playwright/ksn-state.json',
        'refresh_on_blocked' => false,
        'refresh_attempts' => 3,
        'auto_scroll' => true,
        'max_scroll_steps' => 14,
        'scroll_pause_ms' => 750,
        'proxy' => [
            'server' => 'http://proxy.local:8080',
            'username' => 'proxy-user',
            'password' => 'proxy-pass',
            'bypass' => 'localhost,127.0.0.1',
        ],
    ]);

    expect($result)->not->toBeNull()
        ->and($result['body'])->toContain(base_path('storage/app/playwright/ksn-state.json'))
        ->and($result['body'])->toContain('http://proxy.local:8080')
        ->and($result['body'])->toContain('proxy-user')
        ->and($result['body'])->toContain('proxy-pass')
        ->and($result['body'])->toContain('localhost,127.0.0.1')
        ->and($result['body'])->toContain('|0|3|Mozilla/5.0')
        ->and($result['body'])->toContain('|1|14|750');
});

it('ignores placeholder proxy and storage state values', function () {
    $nodeStub = makeExecutableScript('node-stub-env-placeholder.sh', <<<'SH'
#!/bin/sh
printf '{"url":"https://example.com/final","html":"<html><body>%s|%s|%s|%s|%s</body></html>"}' \
  "$PLAYWRIGHT_STORAGE_STATE_PATH" \
  "$PLAYWRIGHT_PROXY_SERVER" \
  "$PLAYWRIGHT_PROXY_USERNAME" \
  "$PLAYWRIGHT_PROXY_PASSWORD" \
  "$PLAYWRIGHT_PROXY_BYPASS"
SH);

    $scriptStub = makeExecutableScript('playwright-script-stub-env-placeholder.mjs', <<<'JS'
// Placeholder script file required by PlaywrightPageFetcher.
JS);

    config()->set('chat.playwright_node_binary', $nodeStub);
    config()->set('chat.playwright_script', $scriptStub);
    config()->set('chat.playwright_timeout', 1000);
    config()->set('chat.playwright_storage_state_path', 'path/to/storage/state');
    config()->set('chat.playwright_proxy_server', 'http://proxy.example.com:8080');
    config()->set('chat.playwright_proxy_username', 'user');
    config()->set('chat.playwright_proxy_password', 'pass');
    config()->set('chat.playwright_proxy_bypass', 'localhost');

    $result = app(PlaywrightPageFetcher::class)->fetch('https://example.com/start', [
        'storage_state_path' => 'path/to/storage/state',
        'proxy' => [
            'server' => 'http://proxy.example.com:8080',
            'username' => 'user',
            'password' => 'pass',
            'bypass' => 'localhost',
        ],
    ]);

    expect($result)->not->toBeNull()
        ->and($result['body'])->not->toContain('path/to/storage/state')
        ->and($result['body'])->not->toContain('proxy.example.com')
        ->and($result['body'])->toContain('<body>||||</body>');
});
