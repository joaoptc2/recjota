<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum UserType: string
{
    case Agency = 'agency';
    case Client = 'client';

    public function label(): string
    {
        return match ($this) {
            self::Agency => 'Equipe da agência',
            self::Client => 'Cliente',
        };
    }
}
