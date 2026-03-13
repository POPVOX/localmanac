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
    ],
];
