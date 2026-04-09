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

    // 1. Добавить в config/services.php:
    'moysklad' => [
        'token'                          => env('MOYSKLAD_TOKEN'),
        'base_url'                       => env('MOYSKLAD_BASE_URL', 'https://api.moysklad.ru/api/remap/1.2'),
        'processing_done_state_name'     => env('MOYSKLAD_PROCESSING_DONE_STATE_NAME', ''),
        'processing_in_work_state_name'  => env('MOYSKLAD_PROCESSING_IN_WORK_STATE_NAME', ''),
    ],

];
