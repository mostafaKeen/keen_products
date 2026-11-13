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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'bitrix24' => [
        'client_id' => env('BITRIX24_CLIENT_ID', ''),
        'client_secret' => env('BITRIX24_CLIENT_SECRET', ''),
        'app_code' => env('BITRIX24_APP_CODE', 'fee_grid_sync'),
        'oauth_url' => env('BITRIX24_OAUTH_URL', 'https://oauth.bitrix.info/oauth/token/'),
        'public_url' => env('BITRIX24_PUBLIC_URL', env('APP_URL', 'http://localhost:8000')),
        'jwt_secret' => env('BITRIX24_JWT_SECRET', ''),
        'jwt_ttl_seconds' => (int) env('BITRIX24_JWT_TTL_SECONDS', 1800),
        'support_email' => env('APP_SUPPORT_EMAIL', 'apps@keenenter.com'),
        'default_trial_days' => (int) env('BITRIX24_DEFAULT_TRIAL_DAYS', 7),
        'grace_period_days' => (int) env('BITRIX24_GRACE_PERIOD_DAYS', 7),
        'widget_tab_title' => env('BITRIX24_WIDGET_TAB_TITLE', 'KEEN Products'),
        'allowed_frame_ancestors' => env('BITRIX24_ALLOWED_FRAME_ANCESTORS', ''),
        'allow_custom_domains' => (bool) env('BITRIX24_ALLOW_CUSTOM_DOMAINS', false),
        'allowed_domains' => env('BITRIX24_ALLOWED_DOMAINS', ''),
        'oauth_allowed_hosts' => ['oauth.bitrix.info', 'oauth.bitrix24.tech'],
    ],

];
