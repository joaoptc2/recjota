<?php

declare(strict_types=1);

namespace App\Support\Enums;

/** Resultado de uma tentativa de renovação de token (Seção 7.1.3). */
enum TokenRefreshOutcome: string
{
    case Refreshed = 'refreshed';
    /** Erro permanente: conta marcada como expirada e gestores avisados. */
    case Expired = 'expired';
    /** Erro transitório: fica para a próxima execução. */
    case Deferred = 'deferred';

    public function label(): string
    {
        return match ($this) {
            self::Refreshed => 'renovado',
            self::Expired => 'expirado',
            self::Deferred => 'adiado',
        };
    }
}
