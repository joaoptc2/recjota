<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Derivados leves da mídia (R8).
 *
 * O servidor guarda miniatura e preview; o original fica onde está. Em
 * hospedagem compartilhada, espaço e inodes acabam antes da paciência.
 */
class MediaProcessor
{
    public const MINIATURA = 320;

    public const PREVIEW = 1080;

    public function __construct(private readonly ImageManager $imagens) {}

    public static function make(): self
    {
        return new self(new ImageManager(new Driver));
    }

    /** MIME real do arquivo, nunca a extensão (Seção 10). */
    public function detectMime(string $caminhoAbsoluto): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = @finfo_file($finfo, $caminhoAbsoluto);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }

    /**
     * Dimensões da imagem. Vídeo precisaria de ffmpeg, que não existe em
     * hospedagem compartilhada — para vídeo as dimensões vêm do navegador.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(string $caminhoAbsoluto): ?array
    {
        $tamanho = @getimagesize($caminhoAbsoluto);

        return $tamanho === false ? null : ['width' => $tamanho[0], 'height' => $tamanho[1]];
    }

    public function checksum(string $caminhoAbsoluto): ?string
    {
        $hash = @hash_file('sha256', $caminhoAbsoluto);

        return $hash === false ? null : $hash;
    }

    /**
     * Gera miniatura e preview em WebP (Seção 9.3) e devolve os caminhos
     * relativos ao disco `local`.
     *
     * @return array{thumb: ?string, preview: ?string}
     */
    public function derivatives(string $caminhoAbsoluto, int $clientId, string $ulid): array
    {
        $pasta = sprintf('clients/%d/derivados', $clientId);

        try {
            $imagem = $this->imagens->read($caminhoAbsoluto);
        } catch (Throwable) {
            // Vídeo ou formato que o GD não abre: segue sem derivados.
            return ['thumb' => null, 'preview' => null];
        }

        $thumb = $pasta.'/'.$ulid.'-thumb.webp';
        $preview = $pasta.'/'.$ulid.'-preview.webp';

        Storage::disk('local')->put(
            $thumb,
            (string) $imagem->scaleDown(width: self::MINIATURA)->toWebp(quality: 72),
        );

        Storage::disk('local')->put(
            $preview,
            (string) $this->imagens->read($caminhoAbsoluto)->scaleDown(width: self::PREVIEW)->toWebp(quality: 82),
        );

        return ['thumb' => $thumb, 'preview' => $preview];
    }

    /** Nome de arquivo seguro: sanitizado e sem chance de colisão (Seção 10). */
    public function safeFilename(UploadedFile $arquivo): string
    {
        $base = Str::of($arquivo->getClientOriginalName())
            ->beforeLast('.')
            ->ascii()
            ->slug('-')
            ->limit(60, '')
            ->toString();

        $extensao = Str::lower($arquivo->getClientOriginalExtension() ?: 'bin');

        return ($base !== '' ? $base : 'arquivo').'-'.Str::random(8).'.'.$extensao;
    }

    /** Duplicata por checksum dentro do mesmo cliente (Seção 6.4). */
    public function findDuplicate(int $clientId, ?string $checksum): ?MediaAsset
    {
        if ($checksum === null) {
            return null;
        }

        return MediaAsset::query()
            ->where('client_id', $clientId)
            ->where('checksum', $checksum)
            ->first();
    }
}
