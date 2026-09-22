<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum CloudProvider: string
{
    case GoogleDrive = 'google_drive';
    case OneDrive = 'onedrive';

    public function label(): string
    {
        return match ($this) {
            self::GoogleDrive => 'Google Drive',
            self::OneDrive => 'OneDrive',
        };
    }

    /** Segmento de URL usado nas rotas de conexão e callback. */
    public function slug(): string
    {
        return match ($this) {
            self::GoogleDrive => 'google',
            self::OneDrive => 'microsoft',
        };
    }

    public static function fromSlug(string $slug): ?self
    {
        foreach (self::cases() as $caso) {
            if ($caso->slug() === $slug) {
                return $caso;
            }
        }

        return null;
    }

    /** Origem de mídia correspondente na biblioteca. */
    public function mediaSource(): MediaSource
    {
        return match ($this) {
            self::GoogleDrive => MediaSource::GoogleDrive,
            self::OneDrive => MediaSource::OneDrive,
        };
    }

    /** Chave em config/services.php. */
    public function configKey(): string
    {
        return match ($this) {
            self::GoogleDrive => 'google',
            self::OneDrive => 'microsoft',
        };
    }
}
