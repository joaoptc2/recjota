<?php

declare(strict_types=1);

/*
|------------------------------------------------------------------------------
| Rede de segurança para a APP_KEY (hospedagem compartilhada, sem SSH)
|------------------------------------------------------------------------------
| Sem APP_KEY o Laravel nem chega a responder: o middleware de cookies precisa
| do encriptador. E sem SSH não existe `php artisan key:generate`.
|
| O pacote de instalação já vem com uma chave gerada. Isto aqui é só o plano B
| para quem montou o diretório à mão: gera a chave uma única vez, grava no .env
| e segue. Roda ANTES do autoload do Composer e não depende de nada.
|
| ATENÇÃO: a chave criptografa os tokens das contas conectadas. Trocá-la depois
| torna todos eles ilegíveis. Guarde uma cópia do .env.
*/

return (static function (string $basePath): void {
    $envPath = $basePath.'/.env';

    if (! is_file($envPath) || ! is_writable($envPath)) {
        return;
    }

    $contents = (string) file_get_contents($envPath);

    if (preg_match('/^APP_KEY=(.+)$/m', $contents, $matches) === 1 && trim($matches[1], " \t\"'") !== '') {
        return;
    }

    try {
        $key = 'base64:'.base64_encode(random_bytes(32));
    } catch (Throwable) {
        return;
    }

    $contents = preg_match('/^APP_KEY=.*$/m', $contents) === 1
        ? (string) preg_replace('/^APP_KEY=.*$/m', 'APP_KEY='.$key, $contents, 1)
        : rtrim($contents, "\n")."\nAPP_KEY=".$key."\n";

    file_put_contents($envPath, $contents, LOCK_EX);
})(dirname(__DIR__));
