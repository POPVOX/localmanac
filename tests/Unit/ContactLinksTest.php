<?php

use App\Support\ContactLinks;
use Tests\TestCase;

uses(TestCase::class);

it('turns source phone numbers into safe telephone links including extensions', function () {
    $html = (string) ContactLinks::html('Call (713) 555-0123 ext. 42 or +1 713-555-4567.');
    expect($html)->toContain('href="tel:+17135550123;ext=42"', '(713) 555-0123 ext. 42', 'href="tel:+17135554567"');
});

it('escapes untrusted source markup and does not link dates or identifiers as phone numbers', function () {
    $html = (string) ContactLinks::html('<script>alert(1)</script> October 14, 2026 ID 1234567890123');
    expect($html)->toContain('&lt;script&gt;')->not->toContain('<script>', 'href=');
    expect(ContactLinks::mapUrl('City Hall & Annex, Houston'))->toBe('https://www.google.com/maps/search/?api=1&query=City%20Hall%20%26%20Annex%2C%20Houston');
});

it('links explicit street addresses without inventing a city or exposing source markup', function () {
    $html = (string) ContactLinks::html('Meet at City Hall, 901 Bagby Street. Call 713-555-0123.');
    expect($html)->toContain('query=901%20Bagby%20Street', '>901 Bagby Street</a>', 'href="tel:+17135550123"')->not->toContain('Houston');
});
