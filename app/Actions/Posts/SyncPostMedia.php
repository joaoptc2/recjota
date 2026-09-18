<?php

declare(strict_types=1);

namespace App\Actions\Posts;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMedia;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Substitui a mídia do post preservando a ordem informada. A posição é o que
 * define a sequência do carrossel, então ela é reescrita do zero a cada
 * salvamento — nunca remendada.
 */
class SyncPostMedia
{
    /**
     * @param  array<int, array{media_asset_id: int, alt_text?: ?string, thumbnail_offset_ms?: ?int}>  $itens
     */
    public function __invoke(Post $post, array $itens): void
    {
        $maximo = $post->type->maxMediaItems();

        if (count($itens) > $maximo) {
            throw new InvalidArgumentException(sprintf(
                '%s aceita no máximo %d %s.',
                $post->type->label(),
                $maximo,
                $maximo === 1 ? 'mídia' : 'itens',
            ));
        }

        $this->assertMesmoCliente($post, $itens);

        DB::transaction(function () use ($post, $itens): void {
            // A posição tem índice único: limpar antes evita colisão ao reordenar.
            PostMedia::where('post_id', $post->getKey())->delete();

            foreach (array_values($itens) as $posicao => $item) {
                PostMedia::create([
                    'post_id' => $post->getKey(),
                    'media_asset_id' => $item['media_asset_id'],
                    'position' => $posicao,
                    'alt_text' => $item['alt_text'] ?? null,
                    'thumbnail_offset_ms' => $item['thumbnail_offset_ms'] ?? null,
                ]);
            }
        });

        $post->load('postMedia.mediaAsset');
    }

    /**
     * Uma mídia de outro cliente dentro de um post seria um vazamento entre
     * tenants pela porta dos fundos.
     *
     * @param  array<int, array{media_asset_id: int}>  $itens
     */
    private function assertMesmoCliente(Post $post, array $itens): void
    {
        if ($itens === []) {
            return;
        }

        $ids = array_column($itens, 'media_asset_id');

        $forasteiras = MediaAsset::query()
            ->withoutClientScope()
            ->whereIn('id', $ids)
            ->where('client_id', '!=', $post->client_id)
            ->count();

        if ($forasteiras > 0) {
            throw new InvalidArgumentException('Há mídia de outro cliente nesta seleção.');
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('A mesma mídia foi adicionada duas vezes.');
        }
    }
}
