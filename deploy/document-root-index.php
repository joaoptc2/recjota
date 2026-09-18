<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Front controller do document root (R9 / Seção 11.1)
|------------------------------------------------------------------------------
| Este arquivo vai para a pasta que o seu domínio publica. Na Hostinger ela
| costuma se chamar `public_html`, mas o nome NÃO importa e não está escrito em
| lugar nenhum daqui: o que importa é que a aplicação fique FORA dela.
|
| Estrutura esperada:
|   .../seudominio.com.br/
|   ├── app/                 ← a aplicação (vendor, .env, storage — fora da web)
|   └── <document root>/     ← este arquivo, .htaccess, build/, media-tmp/
|
| Se a aplicação não estiver exatamente um nível acima, este arquivo procura nos
| lugares vizinhos antes de desistir — e, ao desistir, explica o que houve em
| vez de derrubar um erro fatal ilegível.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/**
 * Uma raiz de aplicação válida tem as duas coisas: o bootstrap do Laravel e o
 * autoloader do Composer. Qualquer pasta chamada "app" sem isso não serve.
 */
$ehRaizValida = static fn (string $caminho): bool => is_file($caminho.'/bootstrap/app.php')
    && is_file($caminho.'/vendor/autoload.php');

$candidatos = [
    __DIR__.'/../app',        // layout do pacote
    dirname(__DIR__),         // projeto inteiro um nível acima
    __DIR__.'/../../app',     // document root aninhado um nível a mais
    dirname(__DIR__, 2),
];

// Última tentativa: qualquer pasta irmã do document root que se qualifique.
foreach ((array) @scandir(dirname(__DIR__)) as $vizinho) {
    if ($vizinho !== '.' && $vizinho !== '..' && is_dir(dirname(__DIR__).'/'.$vizinho)) {
        $candidatos[] = dirname(__DIR__).'/'.$vizinho;
    }
}

$raiz = null;
foreach ($candidatos as $candidato) {
    if ($ehRaizValida($candidato)) {
        $raiz = realpath($candidato) ?: $candidato;
        break;
    }
}

if ($raiz === null) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');

    $procurados = implode("\n", array_map(
        static fn (string $c): string => '  '.htmlspecialchars($c),
        array_slice($candidatos, 0, 4),
    ));

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="pt-BR"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aplicação não encontrada</title>
    <style>
        body{margin:0;padding:40px 16px;background:#f8fafc;color:#0f172a;
             font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        @media(prefers-color-scheme:dark){body{background:#020617;color:#e2e8f0}}
        .w{max-width:620px;margin:0 auto}
        h1{font-size:1.25rem;margin:0 0 12px}
        pre{background:rgba(127,127,127,.12);padding:12px;border-radius:8px;
            overflow-x:auto;font-size:12.5px}
        code{background:rgba(127,127,127,.18);padding:2px 6px;border-radius:4px;font-size:13px}
    </style></head><body><div class="w">
        <h1>A aplicação não foi encontrada</h1>
        <p>Este arquivo está no lugar certo, mas a pasta <code>app</code> do pacote não. Ela precisa ficar
        <strong>ao lado</strong> desta pasta, não dentro dela.</p>
        <p>Procurei em:</p>
        <pre>{$procurados}</pre>
        <p>Uma raiz válida é uma pasta que contenha <code>bootstrap/app.php</code> e
        <code>vendor/autoload.php</code>. Se <code>vendor</code> não estiver lá, o envio por FTP
        provavelmente parou no meio — são milhares de arquivos.</p>
        <p>O arquivo <code>diagnostico.php</code>, que vem no pacote, localiza onde os arquivos foram
        parar.</p>
    </div></body></html>
    HTML;

    exit;
}

// Modo de manutenção (php artisan down).
if (file_exists($manutencao = $raiz.'/storage/framework/maintenance.php')) {
    require $manutencao;
}

// Rede de segurança da APP_KEY para deploy sem SSH.
require $raiz.'/bootstrap/ensure-app-key.php';

require $raiz.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $raiz.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
