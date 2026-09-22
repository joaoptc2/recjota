<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Models\Scopes\ClientScope;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudStorageRegistry;
use App\Services\Integrations\Cloud\CloudTokenManager;
use App\Services\Media\Contracts\MediaSourceReader;
use App\Support\Enums\MediaSource;
use App\Support\Exceptions\MediaBridgeFailed;
use Illuminate\Support\Facades\Log;

/**
 * Leitor de origem para mídia do Google Drive e do OneDrive (Seção 7.4):
 * baixa o original para a pasta temporária fora do webroot e devolve o
 * caminho para a ponte copiar. Um download recente é reaproveitado (o job
 * de publicação pode ser reexecutado).
 */
class CloudSourceReader implements MediaSourceReader
{
    public function __construct(
        private readonly CloudStorageRegistry $registry,
        private readonly CloudTokenManager $tokens,
        private readonly CloudTempStore $temporarios,
    ) {}

    public function supports(MediaAsset $asset): bool
    {
        return in_array($asset->source, [MediaSource::GoogleDrive, MediaSource::OneDrive], true);
    }

    public function localPath(MediaAsset $asset): string
    {
        if (! $this->supports($asset)) {
            throw MediaBridgeFailed::sourceNotSupported($asset);
        }

        $existente = $this->temporarios->existing($asset->ulid);

        if ($existente !== null) {
            return $existente;
        }

        $conexao = $asset->external_account_id !== null
            ? CloudConnection::query()->withoutGlobalScope(ClientScope::class)->find($asset->external_account_id)
            : null;

        if ($conexao === null || $asset->external_file_id === null) {
            throw MediaBridgeFailed::cloudConnectionMissing($asset);
        }

        if ($conexao->needsReconnection()) {
            throw MediaBridgeFailed::cloudConnectionBroken($asset, $conexao);
        }

        $destino = $this->temporarios->pathFor($asset->ulid, $asset->filename);

        try {
            $token = $this->tokens->accessToken($conexao);
            $this->registry->for($conexao->provider)->download($token, $asset->external_file_id, $destino);
        } catch (CloudApiException $e) {
            $this->temporarios->forget($destino);

            Log::warning('Ponte: download da nuvem falhou', [
                'asset_id' => $asset->getKey(),
                'provedor' => $conexao->provider->value,
                'erro' => $e->getMessage(),
            ]);

            throw MediaBridgeFailed::cloudDownloadFailed($asset, $e);
        }

        if (! is_file($destino) || filesize($destino) === 0) {
            $this->temporarios->forget($destino);

            throw MediaBridgeFailed::sourceMissing($asset);
        }

        return $destino;
    }
}
