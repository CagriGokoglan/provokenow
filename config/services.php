<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Countries Stripe Checkout accepts shipping addresses for (ISO 3166-1 alpha-2).
        'shipping_countries' => array_values(array_filter(explode(',', (string) env('STRIPE_SHIPPING_COUNTRIES', 'US')))),
        // Unpaid sessions expire after this many minutes (Stripe minimum: 30); the order is then marked failed.
        'session_ttl_minutes' => max(30, (int) env('STRIPE_SESSION_TTL_MINUTES', 30)),
    ],

    'brevo' => [
        'key' => env('BREVO_API_KEY'),
        'sender' => [
            'email' => env('BREVO_SENDER_EMAIL', 'orders@provokenow.com'),
            'name' => env('BREVO_SENDER_NAME', 'PROVOKE NOW'),
        ],
        'reply_to' => env('BREVO_REPLY_TO', 'care@provokenow.com'),
        // Order emails are Laravel Mailables (app/Mail) sent through the brevo mailer (config/mail.php).
    ],

    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
        'capi_access_token' => env('META_CAPI_ACCESS_TOKEN'),
        'graph_version' => env('META_GRAPH_VERSION', 'v19.0'),
        // Set while validating in Events Manager → Test Events; leave empty in production.
        'test_event_code' => env('META_CAPI_TEST_EVENT_CODE'),
    ],

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

];
