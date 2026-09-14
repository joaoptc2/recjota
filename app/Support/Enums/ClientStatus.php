<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum ClientStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Churned = 'churned';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Ativo',
            self::Paused => 'Pausado',
            self::Churned => 'Encerrado',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
            self::Paused => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
            self::Churned => 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-400/30',
        };
    }
}
