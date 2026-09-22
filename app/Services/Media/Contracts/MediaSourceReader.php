<?php

declare(strict_types=1);

namespace App\Services\Media\Contracts;

use App\Models\MediaAsset;
use App\Support\Exceptions\MediaBridgeFailed;

/**
 * Ponto de extensão da ponte de mídia pública (Seção 7.4).
 *
 * A ponte não sabe de onde vem o original: ela só precisa de um arquivo
 * legível no disco local para validar o MIME real e copiar para a pasta
 * pública. Hoje existe um leitor (upload local); na Fase 5 entram Google
 * Drive e OneDrive, que baixam o arquivo para uma pasta temporária FORA do
 * webroot e devolvem esse caminho. Quem baixou é quem limpa o temporário.
 */
interface MediaSourceReader
{
    /** Se este leitor sabe obter o original deste asset. */
    public function supports(MediaAsset $asset): bool;

    /**
     * Caminho absoluto de um arquivo local legível com o conteúdo original.
     * A ponte apenas LÊ e COPIA esse arquivo; nunca o move nem o apaga.
     *
     * @throws MediaBridgeFailed quando a origem não existe ou não é suportada
     */
    public function localPath(MediaAsset $asset): string;
}
