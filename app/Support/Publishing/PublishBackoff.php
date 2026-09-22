<?php

declare(strict_types=1);

namespace App\Support\Publishing;

use Illuminate\Support\Carbon;

/**
 * Backoff das tentativas de publicação (Seção 8.3): 1 min, 5 min, 15 min,
 * 1 h, 4 h. É regra de negócio, visível ao usuário em next_attempt_at, e não
 * o retry da fila (que roda com --tries=1).
 */
final class PublishBackoff
{
    /** @var array<int, int> minutos de espera após a tentativa N (índice N-1) */
    public const MINUTES = [1, 5, 15, 60, 240];

    /**
     * Espera, em minutos, depois da tentativa informada (1-based). Além da
     * tabela, repete o último degrau — o limite de tentativas cuida do resto.
     */
    public static function minutesAfter(int $attempt): int
    {
        $indice = max(0, min($attempt, count(self::MINUTES)) - 1);

        return self::MINUTES[$indice];
    }

    public static function nextAttemptAt(int $attempt, ?Carbon $from = null): Carbon
    {
        return ($from ?? now())->copy()->addMinutes(self::minutesAfter($attempt));
    }
}
