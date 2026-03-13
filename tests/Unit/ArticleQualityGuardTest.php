<?php

use App\Services\Ingestion\ArticleQualityGuard;

uses(Tests\TestCase::class);

it('rejects items whose source url matches blocked path segments', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', ['staff_profile']);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Kami Steinle',
        'source' => [
            'source_url' => 'https://example.com/staff_profile/kami-steinle',
        ],
        'body' => [
            'cleaned_text' => 'Assistant News Editor',
        ],
    ]);

    expect($reason)->toBe(ArticleQualityGuard::REASON_BLOCKED_URL_PATH);
});

it('rejects bot-challenge pages before any other quality checks', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 0);
    config()->set('ingestion.quality_guard.min_chars', 0);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Wichita City Council — Notes',
        'source' => [
            'source_url' => 'https://docs.google.com/document/d/example/preview',
            'source_type' => 'html',
        ],
        'content_type' => 'html',
        'body' => [
            'cleaned_text' => "window['ppConfig'] = { periodicReportingRateMillis: 60000.0 };",
            'raw_html' => '<html><body>Before we continue...</body></html>',
        ],
    ]);

    expect($reason)->toBe(ArticleQualityGuard::REASON_BOT_CHALLENGE);
});

it('rejects low-content items for min content', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 20);
    config()->set('ingestion.quality_guard.min_chars', 120);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Short note',
        'source' => [
            'source_url' => 'https://example.com/stories/short-note',
            'source_type' => 'html',
        ],
        'content_type' => 'news',
        'body' => [
            'cleaned_text' => 'Quick update.',
        ],
    ]);

    expect($reason)->toBe(ArticleQualityGuard::REASON_MIN_CONTENT);
});

it('does not reject document-like items for min content', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 50);
    config()->set('ingestion.quality_guard.min_chars', 300);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Agenda Packet',
        'source' => [
            'source_url' => 'https://example.com/files/agenda.pdf',
            'source_type' => 'pdf',
        ],
        'content_type' => 'pdf',
        'body' => [
            'cleaned_text' => 'N/A',
        ],
    ]);

    expect($reason)->toBeNull();
});

it('still rejects document-like bot-challenge items', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 50);
    config()->set('ingestion.quality_guard.min_chars', 300);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Agenda Packet',
        'source' => [
            'source_url' => 'https://example.com/files/agenda.pdf',
            'source_type' => 'pdf',
        ],
        'content_type' => 'pdf',
        'body' => [
            'cleaned_text' => 'Checking your browser before we continue.',
            'raw_html' => '<html><head><meta name="description" content="px-captcha"></head></html>',
        ],
    ]);

    expect($reason)->toBe(ArticleQualityGuard::REASON_BOT_CHALLENGE);
});

it('rejects likely profile-title items with role-like short content', function () {
    config()->set('ingestion.quality_guard.enabled', true);
    config()->set('ingestion.quality_guard.blocked_url_segments', []);
    config()->set('ingestion.quality_guard.min_words', 0);
    config()->set('ingestion.quality_guard.min_chars', 0);
    config()->set('ingestion.quality_guard.profile_title_guard.enabled', true);
    config()->set('ingestion.quality_guard.profile_title_guard.max_words', 20);
    config()->set('ingestion.quality_guard.profile_title_guard.role_keywords', ['editor', 'reporter']);

    $reason = app(ArticleQualityGuard::class)->rejectionReason([
        'title' => 'Josh Pfleger-Bernat',
        'source' => [
            'source_url' => 'https://example.com/people/josh',
            'source_type' => 'html',
        ],
        'content_type' => 'news',
        'body' => [
            'cleaned_text' => 'Reporter at the publication.',
        ],
    ]);

    expect($reason)->toBe(ArticleQualityGuard::REASON_PROFILE_TITLE);
});
