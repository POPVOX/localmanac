<?php

use App\Support\Utf8Sanitizer;

it('returns valid utf8 for malformed byte sequences', function () {
    $input = "Project title \xC3\x28 details";

    $sanitized = Utf8Sanitizer::sanitize($input);

    expect(mb_check_encoding($sanitized, 'UTF-8'))->toBeTrue()
        ->and($sanitized)->toContain('Project title')
        ->and($sanitized)->toContain('details');
});

it('preserves normal utf8 text', function () {
    $input = 'City Council hearing notice';

    expect(Utf8Sanitizer::sanitize($input))->toBe($input);
});
