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

    'sos' => [
        'url' => env('SOS_API_URL', 'https://sos.step.co.id/sos/Ajax/tData'),
        'key' => env('SOS_API_KEY'),
        'timeout' => env('SOS_API_TIMEOUT', 60),
        'cache_minutes' => env('SOS_API_CACHE_MINUTES', 5),
        'history_timezone' => env('STOCK_HISTORY_TIMEZONE', 'Asia/Jakarta'),
    ],

    /*
     * TrueType fonts for the chart images in the Daily Stock email (GD). When missing, GD's built-in font is used.
     */
    'charts' => [
        'font' => env('CHART_FONT', 'C:/Windows/Fonts/arial.ttf'),
        'font_bold' => env('CHART_FONT_BOLD', 'C:/Windows/Fonts/arialbd.ttf'),
    ],

];
