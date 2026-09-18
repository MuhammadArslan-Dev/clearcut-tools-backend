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

    'google_sheets' => [
        'credentials' => env('GOOGLE_SHEETS_CREDENTIALS', storage_path('app/google/service-account.json')),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    ],

    'bigquery' => [
        'project_id' => env('BIGQUERY_PROJECT_ID', 'clear-cutoff-435016'),
        'credentials' => env('BIGQUERY_CREDENTIALS', storage_path('app/google/bigquery-service-account.json')),
    ],

];
