<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\Media\Contracts\MediaSourceReader;
use App\Support\Enums\MediaSource;
use App\Support\Exceptions\MediaBridgeFailed;
use Illuminate\Support\Facades\Storage;

/**
 * Leitor de origem para mídia enviada por upload: o original vive no disco
 * `local` (fora do webroot), em `local_path`.
 */
class UploadSourceReader implements MediaSourceReader
{
    public function supports(MediaAsset $asset): bool
    {
        return $asset->source === MediaSource::Upload;
    }

    public function localPath(MediaAsset $asset): string
    {
        if (! $this->supports($asset)) {
            throw MediaBridgeFailed::sourceNotSupported($asset);
        }

        $relativo = (string) $asset->local_path;

        if ($relativo === '' || ! Storage::disk('local')->exists($relativo)) {
            throw MediaBridgeFailed::sourceMissing($asset);
        }

        return Storage::disk('local')->path($relativo);
    }
}
