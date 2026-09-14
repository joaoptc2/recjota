<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CalendarEventType: string
{
    case Meeting = 'meeting';
    case Deadline = 'deadline';
    case Campaign = 'campaign';
    case Holiday = 'holiday';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Meeting => 'Reunião',
            self::Deadline => 'Prazo',
            self::Campaign => 'Campanha',
            self::Holiday => 'Data comemorativa',
            self::Custom => 'Outro',
        };
    }
}
