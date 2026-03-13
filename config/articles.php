<?php

return [
    'text_refresh' => [
        'weak_title_patterns' => [
            '/^the city of\s+/i',
            '/^event date:/i',
            '/^\d+\s+[nsew]\b/i',
            '/published on the city\'?s website/i',
            '/\.(pdf|docx?|txt)$/i',
            '/\blegalnotice\b/i',
            '/\blegalpublish\b/i',
            '/\b[a-z][a-z0-9]{1,12}\s+publish(?:\s+note\b.*)?/i',
            '/^[A-Z0-9._-]{10,}$/',
        ],
        'weak_title_source_patterns' => [
            '/^event date:/i',
            '/published on the city\'?s website/i',
            '/_legalnotice/i',
            '/\blegalpublish\b/i',
            '/\b[a-z][a-z0-9]{1,12}\s+publish(?:\s+note\b.*)?/i',
            '/\.pdf\b/i',
        ],
        'document_headline' => [
            'rewrite_rules' => [
                [
                    'pattern' => '/^bids? for (.+?) will be\b/i',
                    'replacement' => 'Bids sought for $1',
                ],
                [
                    'pattern' => '/^city of [a-z\s]+ notice concerning (.+?) for environmental conditions\b/i',
                    'replacement' => 'Environmental conditions notice for $1',
                ],
                [
                    'pattern' => '/^notice concerning (.+?) for environmental conditions\b/i',
                    'replacement' => 'Environmental conditions notice for $1',
                ],
                [
                    'pattern' => '/^wds t\/w ss t\/w swd to serve (.+)$/i',
                    'replacement' => 'Water and sewer service for $1',
                ],
            ],
            'reject_patterns' => [
                '/^notice concerning\b/i',
                '/^city of [a-z\s]+ notice concerning\b/i',
                '/^bids? for\b/i',
            ],
        ],
    ],
];
