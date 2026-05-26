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

    'workspace' => [
        'access_token' => env('WORKSPACE_ACCESS_TOKEN'),
    ],

    'media_assembly' => [
        'token' => env('MEDIA_ASSEMBLY_TOKEN'),
    ],

    'realtime_publish' => [
        'ca_bundle' => env('HOTLINE_REALTIME_CA_BUNDLE'),
    ],

    'relay' => [
        'hub_json_url' => env('RELAY_HUB_JSON_URL', 'https://relay.pbb.ph/hub.json'),
        'hub_json_timeout' => (int) env('RELAY_HUB_JSON_TIMEOUT', 5),
    ],

];
