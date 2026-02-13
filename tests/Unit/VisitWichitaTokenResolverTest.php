<?php

use App\Services\Ingestion\Fetchers\JsonProfiles\VisitWichitaTokenResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

it('extracts a token from a Visit Wichita request URL', function () {
    $resolver = new VisitWichitaTokenResolver;
    $url = 'https://www.visitwichita.com/includes/rest_v2/plugins_events_events_by_date/find/?json=%7B%7D&token=abc123token';

    expect($resolver->extractTokenFromRequestUrl($url))->toBe('abc123token');
});

it('returns a clear error when the resolver script is missing', function () {
    Http::fake([
        'https://www.visitwichita.com/plugins/core/get_simple_token/' => Http::response('error', 500),
    ]);

    config()->set('services.visit_wichita.token_resolver_script', base_path('scripts/chat/not-real-token-script.mjs'));

    $resolver = new VisitWichitaTokenResolver;
    $result = $resolver->resolve('https://www.visitwichita.com/events/?view=list&sort=date');

    expect($result['token'])->toBeNull()
        ->and($result['error'])->toContain('resolver script');
});

it('reports timeout errors from the resolver process', function () {
    $scriptPath = tempnam(sys_get_temp_dir(), 'vw-token-timeout-');

    if ($scriptPath === false) {
        throw new RuntimeException('Unable to create temporary script file.');
    }

    file_put_contents(
        $scriptPath,
        <<<'PHP'
<?php
sleep(10);
echo json_encode(['token' => 'never-returned']);
PHP
    );

    try {
        Http::fake([
            'https://www.visitwichita.com/plugins/core/get_simple_token/' => Http::response('error', 500),
        ]);

        config()->set('services.visit_wichita.token_resolver_script', $scriptPath);
        config()->set('services.visit_wichita.token_resolver_command', PHP_BINARY);
        config()->set('services.visit_wichita.token_resolver_timeout', 100);

        $resolver = new VisitWichitaTokenResolver;
        $result = $resolver->resolve('https://www.visitwichita.com/events/?view=list&sort=date');

        expect($result['token'])->toBeNull()
            ->and(strtolower($result['error'] ?? ''))->toMatch('/timed out|exceeded the timeout/');
    } finally {
        @unlink($scriptPath);
    }
});

it('reports missing executable errors from the resolver process', function () {
    $scriptPath = tempnam(sys_get_temp_dir(), 'vw-token-exec-');

    if ($scriptPath === false) {
        throw new RuntimeException('Unable to create temporary script file.');
    }

    file_put_contents(
        $scriptPath,
        <<<'PHP'
<?php
echo json_encode(['token' => 'unused']);
PHP
    );

    try {
        Http::fake([
            'https://www.visitwichita.com/plugins/core/get_simple_token/' => Http::response('error', 500),
        ]);

        config()->set('services.visit_wichita.token_resolver_script', $scriptPath);
        config()->set('services.visit_wichita.token_resolver_command', 'command-that-does-not-exist');
        config()->set('services.visit_wichita.token_resolver_timeout', 5000);

        $resolver = new VisitWichitaTokenResolver;
        $result = $resolver->resolve('https://www.visitwichita.com/events/?view=list&sort=date');

        expect($result['token'])->toBeNull()
            ->and($result['error'])->toContain('command-that-does-not-exist');
    } finally {
        @unlink($scriptPath);
    }
});

it('resolves token from direct Visit Wichita token endpoint', function () {
    Http::fake([
        'https://www.visitwichita.com/plugins/core/get_simple_token/' => Http::response('ABCDEF1234567890ABCDEF1234567890', 200),
    ]);

    config()->set('services.visit_wichita.token_resolver_script', base_path('scripts/chat/not-real-token-script.mjs'));

    $resolver = new VisitWichitaTokenResolver;
    $result = $resolver->resolve('https://www.visitwichita.com/events/?view=list&sort=date');

    expect($result['token'])->toBe('abcdef1234567890abcdef1234567890')
        ->and($result['error'])->toBeNull();
});
