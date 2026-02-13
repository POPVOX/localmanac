<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'visit_wichita' => [
        'token_source_url' => env('VISIT_WICHITA_TOKEN_SOURCE_URL', 'https://www.visitwichita.com/events/?view=list&sort=date'),
        'token_resolver_endpoint' => env('VISIT_WICHITA_TOKEN_RESOLVER_ENDPOINT', '/plugins/core/get_simple_token/'),
        'token_resolver_script' => env('VISIT_WICHITA_TOKEN_RESOLVER_SCRIPT', base_path('scripts/chat/resolve-visit-wichita-token.mjs')),
        'token_resolver_timeout' => (int) env('VISIT_WICHITA_TOKEN_RESOLVER_TIMEOUT', 30000),
        'token_resolver_command' => env('VISIT_WICHITA_TOKEN_RESOLVER_COMMAND', 'node'),
    ],

];
