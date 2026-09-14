<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum ApprovalLinkScope: string
{
    case SinglePost = 'single_post';
    case Batch = 'batch';
    case Period = 'period';

    public function label(): string
    {
        return match ($this) {
            self::SinglePost => 'Post único',
            self::Batch => 'Lote',
            self::Period => 'Período',
        };
    }
}
