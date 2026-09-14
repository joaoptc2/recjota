<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum MediaSource: string
{
    case Upload = 'upload';
    case GoogleDrive = 'google_drive';
    case OneDrive = 'onedrive';
    case Url = 'url';

    public function label(): string
    {
        return match ($this) {
            self::Upload => 'Upload',
            self::GoogleDrive => 'Google Drive',
            self::OneDrive => 'OneDrive',
            self::Url => 'URL externa',
        };
    }

    /** Origens cujo arquivo original vive fora do servidor (R8). */
    public function isExternal(): bool
    {
        return $this !== self::Upload;
    }
}
