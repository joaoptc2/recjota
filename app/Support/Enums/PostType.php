<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum PostType: string
{
    case FeedImage = 'feed_image';
    case FeedVideo = 'feed_video';
    case Carousel = 'carousel';
    case Reel = 'reel';
    case Story = 'story';

    public function label(): string
    {
        return match ($this) {
            self::FeedImage => 'Imagem no feed',
            self::FeedVideo => 'Vídeo no feed',
            self::Carousel => 'Carrossel',
            self::Reel => 'Reel',
            self::Story => 'Story',
        };
    }

    public function maxMediaItems(): int
    {
        return $this === self::Carousel ? (int) config('agency.limits.carousel_max_items') : 1;
    }

    /** O Instagram recusa carrossel com um item só (Seção 7.1.4). */
    public function minMediaItems(): int
    {
        return $this === self::Carousel ? (int) config('agency.limits.carousel_min_items', 2) : 1;
    }

    /** Stories só são publicáveis por contas Business (Seção 7.1.5). */
    public function requiresBusinessAccount(): bool
    {
        return $this === self::Story;
    }

    public function acceptsCaption(): bool
    {
        return $this !== self::Story;
    }
}
