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

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'mode' => env('PAYSTACK_MODE', 'test'),           // test | live; must match the key type
        'webhook_ip_allowlist' => array_filter(explode(',', env('PAYSTACK_WEBHOOK_IPS', ''))),
    ],

    'cloudflare_stream' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_STREAM_API_TOKEN'),
        'customer_code' => env('CLOUDFLARE_STREAM_CUSTOMER_CODE'),   // the xxxx in customer-xxxx.cloudflarestream.com
        'key_id' => env('CLOUDFLARE_STREAM_KEY_ID'),                 // signing key id (POST /stream/keys)
        'key_pem_b64' => env('CLOUDFLARE_STREAM_KEY_PEM_B64'),       // base64 PEM private key returned once at creation
        'webhook_secret' => env('CLOUDFLARE_STREAM_WEBHOOK_SECRET'),
        'base_url' => env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'),
    ],

    'clamav' => [
        'enabled' => env('CLAMAV_ENABLED', false),
        'socket' => env('CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl'),
    ],

];
