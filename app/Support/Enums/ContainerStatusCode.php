<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * status_code de um container de mídia do Instagram (Seção 7.1.4).
 * O motor de publicação só chama media_publish quando o container está
 * FINISHED; ERROR e EXPIRED são falhas permanentes daquele container.
 */
enum ContainerStatusCode: string
{
    case Expired = 'EXPIRED';
    case Error = 'ERROR';
    case Finished = 'FINISHED';
    case InProgress = 'IN_PROGRESS';
    case Published = 'PUBLISHED';

    public function isReady(): bool
    {
        return $this === self::Finished;
    }

    public function isFailed(): bool
    {
        return in_array($this, [self::Error, self::Expired], true);
    }

    public function isPending(): bool
    {
        return $this === self::InProgress;
    }
}
