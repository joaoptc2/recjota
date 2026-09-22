<?php

declare(strict_types=1);

namespace App\Support\Enums;

/** Etapa do motor de publicação registrada em publish_logs (Seção 8). */
enum PublishStage: string
{
    case Quota = 'quota';
    case Container = 'container';
    case Status = 'status';
    case Publish = 'publish';
    case Comment = 'comment';

    public function label(): string
    {
        return match ($this) {
            self::Quota => 'Consulta de cota',
            self::Container => 'Criação do container',
            self::Status => 'Processamento da mídia',
            self::Publish => 'Publicação',
            self::Comment => 'Primeiro comentário',
        };
    }
}
