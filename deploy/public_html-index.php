<?php

/*
|------------------------------------------------------------------------------
| Front controller para o document root da Hostinger (R9 / Seção 11.1)
|------------------------------------------------------------------------------
| Copie este arquivo para public_html/index.php. Ele é o index.php padrão do
| Laravel com os caminhos apontando um nível acima, para ../app/, de modo que
| .env, storage/ e vendor/ fiquem FORA do document root.
|
| Estrutura esperada:
|   /home/uXXXXXXXX/domains/seudominio.com.br/
|   ├── app/           ← projeto Laravel (este repositório)
|   └── public_html/   ← document root (este arquivo, .htaccess, build/, storage/, media-tmp/)
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Modo de manutenção (php artisan down).
if (file_exists($maintenance = __DIR__.'/../app/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/../app/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../app/bootstrap/app.php';

$app->handleRequest(Request::capture());
