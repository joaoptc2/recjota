<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudStorageRegistry;
use App\Services\Integrations\Cloud\CloudTokenManager;
use App\Services\Media\CloudTempStore;
use App\Services\Media\MediaProcessor;
use App\Support\DataObjects\CloudFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Vincula um arquivo do Drive/OneDrive à biblioteca (Seções 7.2 e 7.3).
 *
 * Baixa o original para uma pasta temporária FORA do webroot, valida o MIME
 * real, deduplica por checksum, gera miniatura e preview locais e grava o
 * asset com a referência (source + external_file_id + conexão). O original
 * é apagado em seguida: só a referência e os derivados ficam no servidor
 * (R8). Na hora de publicar, a ponte baixa de novo pelo CloudSourceReader.
 */
class ImportCloudFile
{
    public function __construct(
        private readonly CloudStorageRegistry $registry,
        private readonly CloudTokenManager $tokens,
        private readonly MediaProcessor $processador,
        private readonly CloudTempStore $temporarios,
    ) {}

    /**
     * @throws CloudApiException quando o provedor recusa (token, arquivo sumido)
     * @throws RuntimeException quando o arquivo não serve (tipo, tamanho)
     */
    public function __invoke(CloudConnection $conexao, string $fileId, ?int $folderId = null, ?int $userId = null): MediaAsset
    {
        if ($conexao->needsReconnection()) {
            throw new RuntimeException(sprintf(
                'A conexão com o %s precisa ser refeita antes de importar arquivos. Clique em "Reconectar" na página do cliente.',
                $conexao->provider->label(),
            ));
        }

        $cliente = $this->registry->for($conexao->provider);
        $token = $this->tokens->accessToken($conexao);

        $arquivo = $cliente->file($token, $fileId);

        $this->assertImportable($arquivo, $conexao);

        // Já vinculado antes? Devolve o existente em vez de baixar de novo.
        $existente = MediaAsset::query()
            ->where('client_id', $conexao->client_id)
            ->where('external_account_id', $conexao->getKey())
            ->where('external_file_id', $arquivo->id)
            ->first();

        if ($existente !== null) {
            return $existente;
        }

        $temporario = $this->temporarios->pathFor('import-'.Str::ulid(), $arquivo->name);

        try {
            $cliente->download($token, $arquivo->id, $temporario);

            $mime = $this->processador->detectMime($temporario) ?? $arquivo->mimeType;

            if (! in_array($mime, StoreUploadedMedia::MIMES_ACEITOS, true)) {
                throw new RuntimeException(sprintf(
                    'O arquivo "%s" não é um tipo aceito (%s). Escolha JPEG, PNG, WebP, GIF, MP4 ou MOV.',
                    $arquivo->name,
                    $mime ?? 'desconhecido',
                ));
            }

            $checksum = $this->processador->checksum($temporario);
            $duplicata = $this->processador->findDuplicate($conexao->client_id, $checksum);

            if ($duplicata !== null) {
                return $duplicata;
            }

            $dimensoes = $this->processador->dimensions($temporario);
            $tamanho = @filesize($temporario);

            $asset = MediaAsset::create([
                'client_id' => $conexao->client_id,
                'uploaded_by' => $userId ?? auth()->id(),
                'folder_id' => $folderId,
                'source' => $conexao->provider->mediaSource(),
                'external_file_id' => $arquivo->id,
                'external_account_id' => $conexao->getKey(),
                'filename' => $arquivo->name,
                'mime_type' => $mime,
                'size_bytes' => $tamanho === false ? $arquivo->sizeBytes : $tamanho,
                'width' => $dimensoes['width'] ?? null,
                'height' => $dimensoes['height'] ?? null,
                'checksum' => $checksum,
                'local_path' => null,
            ]);

            $derivados = $this->processador->derivatives($temporario, $conexao->client_id, $asset->ulid);

            $asset->forceFill([
                'local_thumb_path' => $derivados['thumb'],
                'local_preview_path' => $derivados['preview'],
            ])->save();

            return $asset;
        } finally {
            $this->temporarios->forget($temporario);
        }
    }

    private function assertImportable(CloudFile $arquivo, CloudConnection $conexao): void
    {
        if ($arquivo->isFolder) {
            throw new RuntimeException(sprintf('"%s" é uma pasta. Abra-a e escolha um arquivo de imagem ou vídeo.', $arquivo->name));
        }

        if (! $arquivo->isMedia()) {
            throw new RuntimeException(sprintf(
                '"%s" não é imagem nem vídeo (%s). Só JPEG, PNG, WebP, GIF, MP4 e MOV entram na biblioteca.',
                $arquivo->name,
                $arquivo->mimeType ?? 'tipo desconhecido',
            ));
        }

        $maximo = (int) config('agency.cloud_temp.max_bytes');

        if ($arquivo->sizeBytes !== null && $maximo > 0 && $arquivo->sizeBytes > $maximo) {
            throw new RuntimeException(sprintf(
                '"%s" tem %s; o limite para publicar no Instagram é %d MB. Reduza o arquivo no %s e tente de novo.',
                $arquivo->name,
                number_format($arquivo->sizeBytes / 1048576, 1, ',', '.').' MB',
                intdiv($maximo, 1048576),
                $conexao->provider->label(),
            ));
        }

        // Garante a pasta de derivados antes de qualquer gravação.
        Storage::disk('local')->makeDirectory(sprintf('clients/%d/derivados', $conexao->client_id));
    }
}
