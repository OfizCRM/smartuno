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

    /*
    |--------------------------------------------------------------------------
    | Socialite Providers
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI', '/auth/github/callback'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI', '/auth/microsoft/callback'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'onesignal' => [
        'app_id' => env('ONESIGNAL_APP_ID', ''),
        'rest_api_key' => env('ONESIGNAL_REST_API_KEY', ''),
    ],

    'qdrant' => [
        'url' => env('QDRANT_URL', ''),
        'api_key' => env('QDRANT_API_KEY', ''),
    ],

    'fast2sms' => [
        'webhook_secret' => env('FAST2SMS_WEBHOOK_SECRET'),
    ],

    'plivo' => [
        'webhook_secret' => env('PLIVO_WEBHOOK_SECRET'),
    ],

    'telnyx' => [
        'webhook_secret' => env('TELNYX_WEBHOOK_SECRET'),
    ],

    'infobip' => [
        'webhook_secret' => env('INFOBIP_WEBHOOK_SECRET'),
    ],

    'clicksend' => [
        'webhook_secret' => env('CLICKSEND_WEBHOOK_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | ONLYOFFICE Docs
    |--------------------------------------------------------------------------
    |
    | The document editor. `url` is where the browser loads the editor from;
    | `app_url` is how the Document Server reaches this application back, which
    | is a different address — a container's "localhost" is the container. On a
    | developer's machine that is http://host.docker.internal:8000; on a server
    | it is whatever the container can resolve.
    |
    | `secret` must match JWT_SECRET on the container. Empty means the editor is
    | switched off, and the application says so rather than half-working.
    |
    */
    'onlyoffice' => [
        'url' => env('ONLYOFFICE_URL', ''),
        'app_url' => env('ONLYOFFICE_APP_URL', env('APP_URL')),
        'secret' => env('ONLYOFFICE_SECRET', ''),
    ],

];
