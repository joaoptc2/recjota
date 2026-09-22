<?php

declare(strict_types=1);

namespace App\Support\Enums;

/** Valor de media_type aceito por POST /{ig-user-id}/media. Imagem única não envia o campo. */
enum MediaContainerType: string
{
    case Image = 'IMAGE';
    case Video = 'VIDEO';
    case Reels = 'REELS';
    case Stories = 'STORIES';
    case Carousel = 'CAROUSEL';

    /** Imagem de feed é o padrão da API e não leva media_type no payload. */
    public function isSentToApi(): bool
    {
        return $this !== self::Image;
    }
}
