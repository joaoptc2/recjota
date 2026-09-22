<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Services\Media\Contracts\MediaSourceReader;
use App\Support\Exceptions\MediaBridgeFailed;

/** Escolhe o leitor pela origem do asset: upload local ou nuvem. */
class CompositeSourceReader implements MediaSourceReader
{
    /** @var array<int, MediaSourceReader> */
    private array $leitores;

    public function __construct(MediaSourceReader ...$leitores)
    {
        $this->leitores = $leitores;
    }

    public function supports(MediaAsset $asset): bool
    {
        return $this->readerFor($asset) !== null;
    }

    public function localPath(MediaAsset $asset): string
    {
        $leitor = $this->readerFor($asset);

        if ($leitor === null) {
            throw MediaBridgeFailed::sourceNotSupported($asset);
        }

        return $leitor->localPath($asset);
    }

    private function readerFor(MediaAsset $asset): ?MediaSourceReader
    {
        foreach ($this->leitores as $leitor) {
            if ($leitor->supports($asset)) {
                return $leitor;
            }
        }

        return null;
    }
}
