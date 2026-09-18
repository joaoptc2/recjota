<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Front controller do modo pasta única
|------------------------------------------------------------------------------
| Só entra em ação quando o projeto inteiro está dentro da pasta publicada pelo
| domínio. No layout recomendado (duas pastas) este arquivo fica fora do
| document root e nunca é executado.
|
| Ele existe para que um projeto no lugar "errado" responda alguma coisa
| compreensível, em vez de 403 ou de um erro fatal de include.
*/

$raiz = __DIR__;

if (! is_file($raiz.'/vendor/autoload.php') || ! is_file($raiz.'/public/index.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');

    $faltaVendor = ! is_dir($raiz.'/vendor');

    $explicacao = $faltaVendor
        ? 'A pasta <code>vendor</code> não está aqui. Ela contém as bibliotecas que fazem o sistema '
          .'funcionar e <strong>não vem no "Code › Download ZIP" do GitHub</strong> — só no pacote de '
          .'instalação, na página de Releases do repositório.'
        : 'A pasta <code>public</code> não está aqui. O envio provavelmente ficou incompleto.';

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="pt-BR"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instalação incompleta</title>
    <style>
        body{margin:0;padding:40px 16px;background:#f8fafc;color:#0f172a;
             font:15px/1.6 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        @media(prefers-color-scheme:dark){body{background:#020617;color:#e2e8f0}}
        .w{max-width:620px;margin:0 auto}
        h1{font-size:1.25rem;margin:0 0 12px}
        code{background:rgba(127,127,127,.18);padding:2px 6px;border-radius:4px;font-size:13px}
    </style></head><body><div class="w">
        <h1>Instalação incompleta</h1>
        <p>{$explicacao}</p>
        <p>Baixe o pacote de instalação e envie o conteúdo dele para esta pasta. O arquivo
        <code>diagnostico.php</code>, que vem no pacote, aponta exatamente o que está faltando.</p>
    </div></body></html>
    HTML;

    exit;
}

require $raiz.'/public/index.php';
