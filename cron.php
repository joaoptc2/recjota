<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArrayInput;

/*
|------------------------------------------------------------------------------
| Ponto de entrada do cron em PHP puro (Seção 8.1, R3)
|------------------------------------------------------------------------------
| Alternativa ao cron.sh para quando o hPanel só oferece o tipo "PHP", que não
| aceita os caracteres > e & de uma linha de shell.
|
| Cadastre em hPanel › Avançado › Tarefas Cron, a cada minuto:
|
|   Tipo: PHP
|   Comando/arquivo: /home/uXXXXXXXX/domains/seudominio.com.br/app/cron.php
|
| Faz exatamente o que `php artisan schedule:run` faria.
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__.'/bootstrap/ensure-app-key.php';
require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$status = $kernel->call('schedule:run');

echo $kernel->output();

$kernel->terminate(new ArrayInput([]), $status);

exit($status);
