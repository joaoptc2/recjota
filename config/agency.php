<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Identidade da agencia
    |---------------------------------------------------------------------------
    | Valores padrao usados no branding do painel, dos e-mails transacionais e
    | dos relatorios. Cada cliente pode sobrescrever cor e logo (white-label).
    */
    'name' => env('AGENCY_NAME', 'Recjota'),
    'support_email' => env('AGENCY_SUPPORT_EMAIL', 'contato@example.com'),
    'primary_color' => env('AGENCY_PRIMARY_COLOR', '#4F46E5'),

    /*
    |---------------------------------------------------------------------------
    | Fuso horario
    |---------------------------------------------------------------------------
    | O banco e os jobs operam SEMPRE em UTC (R2). Este e o fuso padrao aplicado
    | a novos clientes e usuarios, usado apenas na camada de apresentacao.
    */
    'default_timezone' => env('AGENCY_DEFAULT_TIMEZONE', 'America/Sao_Paulo'),

    /*
    |---------------------------------------------------------------------------
    | Aprovacao
    |---------------------------------------------------------------------------
    */
    'approval' => [
        'default_deadline_hours' => 48,
        'default_min_approvals' => 1,
        'magic_link_ttl_hours' => 168,
        'magic_link_token_bytes' => 32,
    ],

    /*
    |---------------------------------------------------------------------------
    | Ponte de midia publica (Secao 7.4) - usada a partir da Fase 4
    |---------------------------------------------------------------------------
    */
    'media_bridge' => [
        'path' => env('MEDIA_BRIDGE_PATH', public_path('media-tmp')),
        'url' => env('MEDIA_BRIDGE_URL', env('APP_URL').'/media-tmp'),
        'ttl_hours' => (int) env('MEDIA_BRIDGE_TTL_HOURS', 6),
    ],

    /*
    |---------------------------------------------------------------------------
    | Limites da plataforma impostos pelo proprio sistema (Secao 7.1.5)
    |---------------------------------------------------------------------------
    */
    'limits' => [
        'caption_max_chars' => 2200,
        'caption_truncate_at' => 125,
        'hashtags_max' => 30,
        'mentions_max' => 50,
        'carousel_max_items' => 10,
        'publish_per_24h' => 50,
    ],
];
