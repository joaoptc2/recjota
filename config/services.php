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

    /*
    |--------------------------------------------------------------------------
    | Google Drive — escopo drive.file + Picker (Seção 7.2)
    |--------------------------------------------------------------------------
    | drive.file é escopo não sensível: o app só enxerga o que o usuário
    | escolheu no Picker. O refresh token fica no servidor (cast encrypted)
    | para que a ponte de mídia baixe o original na hora de publicar, sem
    | ninguém logado.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'api_key' => env('GOOGLE_API_KEY'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'scopes' => [
            'https://www.googleapis.com/auth/drive.file',
            'https://www.googleapis.com/auth/userinfo.email',
        ],
        'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url' => 'https://oauth2.googleapis.com/token',
        'api_url' => 'https://www.googleapis.com',
        'timeout' => 8,
        // Download do original para a ponte: roda no cron, pode demorar mais.
        'download_timeout' => 40,
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft OneDrive / SharePoint — Entra ID + Microsoft Graph (Seção 7.3)
    |--------------------------------------------------------------------------
    | App multi-tenant (MS_TENANT=common aceita contas pessoais e corporativas).
    | Sites.Read.All é o que permite o token de SharePoint no File Picker.
    */
    'microsoft' => [
        'client_id' => env('MS_CLIENT_ID'),
        'client_secret' => env('MS_CLIENT_SECRET'),
        'tenant' => env('MS_TENANT', 'common'),
        'redirect_uri' => env('MS_REDIRECT_URI'),
        'scopes' => [
            'openid',
            'email',
            'offline_access',
            'User.Read',
            'Files.Read',
            'Sites.Read.All',
        ],
        'login_url' => 'https://login.microsoftonline.com',
        'graph_url' => 'https://graph.microsoft.com/v1.0',
        'timeout' => 8,
        'download_timeout' => 40,
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
