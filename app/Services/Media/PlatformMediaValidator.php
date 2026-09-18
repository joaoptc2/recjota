<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\DataObjects\MediaValidationResult;
use App\Support\Enums\PostType;

/**
 * Regras de mídia da plataforma (Seção 7.4), aplicadas no composer e não na
 * hora de publicar.
 *
 * O objetivo é justamente esse: reprovar às 14h de terça, com a pessoa olhando
 * a tela, em vez de falhar às 21h de sábado dentro de um job.
 */
class PlatformMediaValidator
{
    public const IMAGEM_MAX_BYTES = 8 * 1024 * 1024;

    public const VIDEO_MAX_BYTES = 100 * 1024 * 1024;

    public const LARGURA_MINIMA = 320;

    /** Proporções aceitas no feed: de 4:5 (0.8) a 1.91:1. */
    public const PROPORCAO_MINIMA = 0.8;

    public const PROPORCAO_MAXIMA = 1.91;

    public const REEL_MAX_SEGUNDOS = 90;

    public const STORY_MAX_SEGUNDOS = 60;

    public const VIDEO_FEED_MIN_SEGUNDOS = 3;

    public const VIDEO_FEED_MAX_SEGUNDOS = 3600;

    /** @var array<int, string> */
    public const IMAGEM_MIMES = ['image/jpeg', 'image/png'];

    /** @var array<int, string> */
    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime'];

    public function forAsset(MediaAsset $asset, PostType $tipo): MediaValidationResult
    {
        return $asset->isVideo()
            ? $this->video($asset, $tipo)
            : $this->imagem($asset, $tipo);
    }

    /** Valida o post inteiro: quantidade de itens, tipo de conta e cada mídia. */
    public function forPost(Post $post): MediaValidationResult
    {
        $resultado = new MediaValidationResult;
        $midias = $post->media;

        if ($midias->isEmpty()) {
            // Post só com texto não existe no Instagram (Seção 7.1.5).
            return new MediaValidationResult(['Adicione ao menos uma mídia: o Instagram não publica post só com texto.']);
        }

        $maximo = $post->type->maxMediaItems();

        if ($midias->count() > $maximo) {
            $resultado = $resultado->merge(new MediaValidationResult([
                sprintf('%s aceita no máximo %d %s.', $post->type->label(), $maximo, $maximo === 1 ? 'mídia' : 'itens'),
            ]));
        }

        if ($post->type->requiresBusinessAccount() && $post->socialAccount !== null
            && ! $post->socialAccount->canPublishStories()) {
            $resultado = $resultado->merge(new MediaValidationResult([
                'Stories por API exigem conta Business. Esta conta é Creator — troque o tipo do post ou a conta.',
            ]));
        }

        foreach ($midias as $asset) {
            $resultado = $resultado->merge($this->forAsset($asset, $post->type));
        }

        return $resultado;
    }

    private function imagem(MediaAsset $asset, PostType $tipo): MediaValidationResult
    {
        $erros = [];
        $avisos = [];
        $nome = $asset->filename;

        if ($asset->mime_type !== null && ! in_array($asset->mime_type, self::IMAGEM_MIMES, true)) {
            $erros[] = sprintf('%s: o Instagram aceita apenas JPEG e PNG em imagens.', $nome);
        }

        if ($asset->size_bytes !== null && $asset->size_bytes > self::IMAGEM_MAX_BYTES) {
            $erros[] = sprintf('%s: %s — o limite para imagem é 8 MB.', $nome, $this->emMegabytes($asset->size_bytes));
        }

        if ($asset->width !== null && $asset->width < self::LARGURA_MINIMA) {
            $erros[] = sprintf('%s: %dpx de largura — o mínimo é %dpx.', $nome, $asset->width, self::LARGURA_MINIMA);
        }

        $proporcao = $asset->aspectRatio();

        if ($proporcao !== null && $tipo !== PostType::Story) {
            if ($proporcao < self::PROPORCAO_MINIMA || $proporcao > self::PROPORCAO_MAXIMA) {
                $erros[] = sprintf(
                    '%s: proporção %s fora do aceito pelo feed (entre 4:5 e 1.91:1). Recorte antes de agendar.',
                    $nome,
                    $this->comoFracao($proporcao),
                );
            }
        }

        if ($tipo === PostType::Story && $proporcao !== null && abs($proporcao - (9 / 16)) > 0.05) {
            $avisos[] = sprintf('%s: Stories ficam melhores em 9:16; esta mídia está em %s.', $nome, $this->comoFracao($proporcao));
        }

        return new MediaValidationResult($erros, $avisos);
    }

    private function video(MediaAsset $asset, PostType $tipo): MediaValidationResult
    {
        $erros = [];
        $avisos = [];
        $nome = $asset->filename;

        if ($asset->mime_type !== null && ! in_array($asset->mime_type, self::VIDEO_MIMES, true)) {
            $erros[] = sprintf('%s: o Instagram aceita apenas MP4 e MOV em vídeo.', $nome);
        }

        if ($asset->size_bytes !== null && $asset->size_bytes > self::VIDEO_MAX_BYTES) {
            $erros[] = sprintf('%s: %s — o limite para vídeo é 100 MB.', $nome, $this->emMegabytes($asset->size_bytes));
        }

        $segundos = $asset->duration_ms !== null ? $asset->duration_ms / 1000 : null;

        if ($segundos !== null) {
            $limite = match ($tipo) {
                PostType::Reel => self::REEL_MAX_SEGUNDOS,
                PostType::Story => self::STORY_MAX_SEGUNDOS,
                default => self::VIDEO_FEED_MAX_SEGUNDOS,
            };

            if ($segundos > $limite) {
                $erros[] = sprintf('%s: %ds de duração — o limite para %s é %ds.', $nome, (int) $segundos, $tipo->label(), $limite);
            }

            if ($tipo === PostType::FeedVideo && $segundos < self::VIDEO_FEED_MIN_SEGUNDOS) {
                $erros[] = sprintf('%s: %ds — vídeo de feed precisa de ao menos %ds.', $nome, (int) $segundos, self::VIDEO_FEED_MIN_SEGUNDOS);
            }
        }

        $proporcao = $asset->aspectRatio();

        if ($proporcao !== null && in_array($tipo, [PostType::Reel, PostType::Story], true)
            && abs($proporcao - (9 / 16)) > 0.05) {
            $avisos[] = sprintf('%s: %s ficam melhores em 9:16; esta mídia está em %s.', $nome, $tipo->label(), $this->comoFracao($proporcao));
        }

        return new MediaValidationResult($erros, $avisos);
    }

    private function emMegabytes(int $bytes): string
    {
        return number_format($bytes / 1048576, 1, ',', '.').' MB';
    }

    /** Traduz 0.8 em "4:5", que é como a pessoa pensa sobre a imagem. */
    private function comoFracao(float $proporcao): string
    {
        $conhecidas = ['1:1' => 1.0, '4:5' => 0.8, '9:16' => 0.5625, '16:9' => 1.7778, '1.91:1' => 1.91, '3:4' => 0.75];

        foreach ($conhecidas as $rotulo => $valor) {
            if (abs($proporcao - $valor) < 0.02) {
                return $rotulo;
            }
        }

        return number_format($proporcao, 2, ',', '.').':1';
    }
}
