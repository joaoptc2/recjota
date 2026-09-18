<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\MediaAsset;
use DomainException;
use Illuminate\Support\Facades\Storage;

class DeleteMediaAsset
{
    public function __invoke(MediaAsset $asset, bool $apagarArquivos = true): void
    {
        $emUso = $asset->posts()->count();

        if ($emUso > 0) {
            throw new DomainException(sprintf(
                'Esta mídia está em %d post%s. Remova-a deles antes de excluir.',
                $emUso,
                $emUso === 1 ? '' : 's',
            ));
        }

        if ($apagarArquivos) {
            foreach ([$asset->local_path, $asset->local_thumb_path, $asset->local_preview_path] as $caminho) {
                if ($caminho !== null) {
                    Storage::disk('local')->delete($caminho);
                }
            }
        }

        $asset->delete();
    }
}
