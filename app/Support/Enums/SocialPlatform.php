<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum SocialPlatform: string
{
    case Instagram = 'instagram';
    case Facebook = 'facebook';
    case TikTok = 'tiktok';
    case LinkedIn = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::Instagram => 'Instagram',
            self::Facebook => 'Facebook',
            self::TikTok => 'TikTok',
            self::LinkedIn => 'LinkedIn',
        };
    }

    /** Plataformas com publisher implementado. As demais ficam para fases futuras. */
    public function isPublishable(): bool
    {
        return $this === self::Instagram;
    }
}
