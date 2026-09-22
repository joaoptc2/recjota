<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use Illuminate\Support\Carbon;

/** Um item de Drive/OneDrive como a biblioteca precisa vê-lo. */
final readonly class CloudFile
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $mimeType,
        public ?int $sizeBytes,
        public bool $isFolder,
        public ?Carbon $modifiedAt = null,
        public ?string $thumbnailUrl = null,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mimeType, 'image/');
    }

    public function isVideo(): bool
    {
        return str_starts_with((string) $this->mimeType, 'video/');
    }

    /** Só imagem e vídeo interessam à biblioteca; o resto aparece desabilitado. */
    public function isMedia(): bool
    {
        return $this->isImage() || $this->isVideo();
    }
}
