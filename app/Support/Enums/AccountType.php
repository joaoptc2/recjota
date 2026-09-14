<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum AccountType: string
{
    case Business = 'business';
    case Creator = 'creator';

    public function label(): string
    {
        return match ($this) {
            self::Business => 'Business',
            self::Creator => 'Creator',
        };
    }

    public function canPublishStories(): bool
    {
        return $this === self::Business;
    }
}
