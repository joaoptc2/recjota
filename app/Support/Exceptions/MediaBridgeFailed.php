<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Models\MediaAsset;
use DomainException;

/**
 * A ponte de mídia pública (Seção 7.4) não conseguiu expor o arquivo.
 *
 * É erro PERMANENTE do ponto de vista da publicação: repetir o job não vai
 * ajudar. Toda mensagem diz o que aconteceu e o que a pessoa deve fazer.
 */
final class MediaBridgeFailed extends DomainException
{
    public static function unsupportedMime(MediaAsset $asset, ?string $mimeDetectado, array $aceitos): self
    {
        return new self(sprintf(
            'O arquivo "%s" é do tipo %s, que o Instagram não aceita. Envie a mídia como %s e substitua o arquivo no post.',
            $asset->filename,
            $mimeDetectado ?? 'desconhecido',
            implode(', ', $aceitos),
        ));
    }

    public static function sourceMissing(MediaAsset $asset): self
    {
        return new self(sprintf(
            'O arquivo original de "%s" não foi encontrado no servidor. Envie a mídia novamente e substitua o arquivo no post.',
            $asset->filename,
        ));
    }

    public static function sourceNotSupported(MediaAsset $asset): self
    {
        return new self(sprintf(
            'A mídia "%s" vem de %s, origem que ainda não pode ser publicada automaticamente. Envie o arquivo por upload e substitua-o no post.',
            $asset->filename,
            $asset->source?->label() ?? 'origem desconhecida',
        ));
    }

    public static function unwritable(string $caminho): self
    {
        return new self(sprintf(
            'Não foi possível gravar a cópia pública em "%s". Verifique se MEDIA_BRIDGE_PATH aponta para uma pasta gravável dentro do site.',
            $caminho,
        ));
    }

    public static function misconfigured(string $motivo): self
    {
        return new self(sprintf(
            'A ponte de mídia pública está mal configurada: %s. Ajuste MEDIA_BRIDGE_PATH e MEDIA_BRIDGE_URL no .env.',
            $motivo,
        ));
    }
}
