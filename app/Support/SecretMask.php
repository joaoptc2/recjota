<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Mascaramento de segredos para log (Seção 10).
 *
 * Um token nunca aparece inteiro em log: mostramos só os 4 primeiros e os
 * 4 últimos caracteres, o bastante para reconhecer qual token é sem permitir
 * usá-lo. Valores curtos demais viram máscara completa.
 */
final class SecretMask
{
    public const VISIBLE = 4;

    public static function mask(?string $secret): string
    {
        if ($secret === null || $secret === '') {
            return '';
        }

        $length = mb_strlen($secret);

        if ($length <= self::VISIBLE * 2) {
            return str_repeat('*', $length);
        }

        return mb_substr($secret, 0, self::VISIBLE)
            .'…'
            .mb_substr($secret, -self::VISIBLE);
    }

    /**
     * Substitui toda ocorrência dos segredos informados dentro de um texto
     * (mensagem de exceção, URL, corpo de resposta) pela versão mascarada.
     *
     * @param  array<int, string|null>  $secrets
     */
    public static function scrub(string $text, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret === null || $secret === '') {
                continue;
            }

            $text = str_replace([$secret, urlencode($secret)], self::mask($secret), $text);
        }

        return $text;
    }
}
