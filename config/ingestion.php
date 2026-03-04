<?php

return [
    'quality_guard' => [
        'enabled' => (bool) env('INGESTION_QUALITY_GUARD_ENABLED', true),
        'min_words' => (int) env('INGESTION_QUALITY_GUARD_MIN_WORDS', 12),
        'min_chars' => (int) env('INGESTION_QUALITY_GUARD_MIN_CHARS', 80),
        'blocked_url_segments' => array_values(array_filter(array_map(
            'trim',
            explode(
                ',',
                (string) env(
                    'INGESTION_QUALITY_GUARD_BLOCKED_URL_SEGMENTS',
                    'staff_profile,staff_name,author,staff,category,tag'
                )
            )
        ))),
        'profile_title_guard' => [
            'enabled' => (bool) env('INGESTION_QUALITY_GUARD_PROFILE_TITLE_ENABLED', true),
            'max_words' => (int) env('INGESTION_QUALITY_GUARD_PROFILE_TITLE_MAX_WORDS', 40),
            'role_keywords' => array_values(array_filter(array_map(
                'trim',
                explode(
                    ',',
                    (string) env(
                        'INGESTION_QUALITY_GUARD_PROFILE_ROLE_KEYWORDS',
                        'reporter,editor,writer,columnist,producer,photographer,intern,staff'
                    )
                )
            ))),
        ],
    ],
];
