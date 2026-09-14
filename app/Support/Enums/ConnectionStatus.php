<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum ConnectionStatus: string
{
    case Connected = 'connected';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Conectada',
            self::Expired => 'Token expirado',
            self::Revoked => 'Acesso revogado',
            self::Error => 'Com erro',
        };
    }

    public function isHealthy(): bool
    {
        return $this === self::Connected;
    }

    /** Semáforo da tela "Saúde das integrações" (Seção 6.12). */
    public function trafficLight(): string
    {
        return match ($this) {
            self::Connected => 'green',
            self::Expired => 'amber',
            self::Revoked, self::Error => 'red',
        };
    }
}
