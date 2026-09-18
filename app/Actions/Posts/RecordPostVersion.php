<?php

declare(strict_types=1);

namespace App\Actions\Posts;

use App\Models\Post;
use App\Models\PostVersion;

/**
 * Congela o estado do post numa versão imutável (Seção 6.6).
 *
 * É esta trilha que permite responder, meses depois, "o cliente aprovou
 * exatamente o quê?" — e é ela que impede publicar algo diferente do aprovado.
 */
class RecordPostVersion
{
    public function __invoke(Post $post, ?string $resumo = null, ?int $autorId = null): PostVersion
    {
        $post->loadMissing('postMedia.mediaAsset');

        return PostVersion::create([
            'post_id' => $post->getKey(),
            'version' => $post->current_version,
            'snapshot' => $this->snapshot($post),
            'change_summary' => $resumo,
            'created_by' => $autorId ?? auth()->id(),
        ]);
    }

    /** @return array<string, mixed> */
    private function snapshot(Post $post): array
    {
        return [
            'type' => $post->type->value,
            'caption' => $post->caption,
            'first_comment' => $post->first_comment,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'social_account_id' => $post->social_account_id,
            'campaign_id' => $post->campaign_id,
            'status' => $post->status->value,
            'media' => $post->postMedia->map(fn ($item) => [
                'media_asset_id' => $item->media_asset_id,
                'position' => $item->position,
                'alt_text' => $item->alt_text,
                'thumbnail_offset_ms' => $item->thumbnail_offset_ms,
                'filename' => $item->mediaAsset?->filename,
                'checksum' => $item->mediaAsset?->checksum,
            ])->all(),
        ];
    }
}
