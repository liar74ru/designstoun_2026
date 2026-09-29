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

    'moysklad' => [
        'token'    => env('MOYSKLAD_TOKEN'),
        'base_url' => env('MOYSKLAD_BASE_URL', 'https://api.moysklad.ru/api/remap/1.2'),

        // Сек. на ответ и на соединение: без лимита зависший МойСклад держал запрос
        // пользователя до max_execution_time
        'timeout'         => (int) env('MOYSKLAD_TIMEOUT', 20),
        'connect_timeout' => (int) env('MOYSKLAD_CONNECT_TIMEOUT', 5),
        // Пауза перед повтором, мс; растёт вдвое с каждой попыткой (429 — по заголовку МойСклад)
        'retry_delay_ms'  => (int) env('MOYSKLAD_RETRY_DELAY_MS', 500),
    ],

];
