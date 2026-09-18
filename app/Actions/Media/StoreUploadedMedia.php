<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Client;
use App\Models\MediaAsset;
use App\Services\Media\MediaProcessor;
use App\Support\Enums\MediaSource;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Upload para a biblioteca (Seção 6.4).
 *
 * O arquivo vai para o disco `local`, fora do webroot; só a miniatura e o
 * preview ficam disponíveis para a interface, servidos por rota do Laravel —
 * sem symlink, que não existe em hospedagem compartilhada.
 */
class StoreUploadedMedia
{
    /** @var array<int, string> */
    public const MIMES_ACEITOS = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'video/mp4', 'video/quicktime',
    ];

    public function __construct(private readonly MediaProcessor $processador) {}

    public function __invoke(
        Client $cliente,
        UploadedFile $arquivo,
        ?int $folderId = null,
        ?int $uploaderId = null,
        ?int $width = null,
        ?int $height = null,
        ?int $durationMs = null,
    ): MediaAsset {
        $caminhoTemporario = $arquivo->getRealPath();

        if ($caminhoTemporario === false) {
            throw new RuntimeException('O arquivo enviado não pôde ser lido.');
        }

        // MIME real, não a extensão: extensão é palpite do cliente (Seção 10).
        $mime = $this->processador->detectMime($caminhoTemporario) ?? $arquivo->getMimeType();

        if (! in_array($mime, self::MIMES_ACEITOS, true)) {
            throw new RuntimeException(sprintf(
                'Tipo de arquivo não aceito (%s). Envie JPEG, PNG, WebP, GIF, MP4 ou MOV.',
                $mime ?? 'desconhecido',
            ));
        }

        $checksum = $this->processador->checksum($caminhoTemporario);
        $duplicata = $this->processador->findDuplicate($cliente->getKey(), $checksum);

        if ($duplicata !== null) {
            // Devolve a existente em vez de gastar disco com o mesmo arquivo.
            return $duplicata;
        }

        $nome = $this->processador->safeFilename($arquivo);
        $dimensoes = $this->processador->dimensions($caminhoTemporario);

        $caminho = $arquivo->storeAs(
            sprintf('clients/%d/midia', $cliente->getKey()),
            Str::ulid().'-'.$nome,
            'local',
        );

        if ($caminho === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo. Verifique a permissão de storage/.');
        }

        $asset = MediaAsset::create([
            'client_id' => $cliente->getKey(),
            'uploaded_by' => $uploaderId ?? auth()->id(),
            'folder_id' => $folderId,
            'source' => MediaSource::Upload,
            'filename' => $arquivo->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $arquivo->getSize(),
            'width' => $dimensoes['width'] ?? $width,
            'height' => $dimensoes['height'] ?? $height,
            'duration_ms' => $durationMs,
            'checksum' => $checksum,
            'local_path' => $caminho,
        ]);

        // O ULID é gerado pelo model (fica fora do fillable), então os
        // derivados só podem ser nomeados depois que o registro existe.
        $derivados = $this->processador->derivatives(
            Storage::disk('local')->path($caminho),
            $cliente->getKey(),
            $asset->ulid,
        );

        $asset->forceFill([
            'local_thumb_path' => $derivados['thumb'],
            'local_preview_path' => $derivados['preview'],
        ])->save();

        return $asset;
    }
}
