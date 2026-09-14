<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum BriefStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Submitted => 'Enviado',
            self::InProgress => 'Em produção',
            self::Done => 'Atendido',
            self::Archived => 'Arquivado',
        };
    }
}
