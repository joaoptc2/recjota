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
}
