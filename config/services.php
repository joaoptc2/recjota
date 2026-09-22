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

    /*
    |--------------------------------------------------------------------------
    | Instagram — API with Instagram Login (Seção 7.1)
    |--------------------------------------------------------------------------
    | Host graph.instagram.com. As chaves ficam no .env (IG_*); nada aqui é
    | sensível por si só, mas o app_secret nunca pode aparecer em log.
    */
    'instagram' => [
        'app_id' => env('IG_APP_ID'),
        'app_secret' => env('IG_APP_SECRET'),
        'redirect_uri' => env('IG_REDIRECT_URI'),
        'api_version' => env('IG_API_VERSION', 'v23.0'),
        'webhook_verify_token' => env('IG_WEBHOOK_VERIFY_TOKEN'),
        'graph_host' => 'https://graph.instagram.com',
        'oauth_host' => 'https://api.instagram.com',
        'authorize_url' => 'https://www.instagram.com/oauth/authorize',
        'scopes' => [
            'instagram_business_basic',
            'instagram_business_content_publish',
            'instagram_business_manage_comments',
        ],
        // Tempo de vida do token de longa duração quando a API não informa.
        'token_ttl_days' => 60,
        // Tempo máximo por chamada: a hospedagem derruba requisições > ~10s.
        'timeout' => 8,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
