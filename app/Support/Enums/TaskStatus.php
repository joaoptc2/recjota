<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Review = 'review';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'A fazer',
            self::Doing => 'Em andamento',
            self::Review => 'Em revisão',
            self::Done => 'Concluída',
        };
    }
}
