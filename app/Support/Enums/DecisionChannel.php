<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum DecisionChannel: string
{
    case Portal = 'portal';
    case MagicLink = 'magic_link';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Portal => 'Portal',
            self::MagicLink => 'Link mágico',
            self::Email => 'E-mail',
        };
    }
}
