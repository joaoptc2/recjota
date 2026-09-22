<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Post;
use App\Models\PostMedia;
use App\Services\Media\PublicMediaBridge;
use App\Support\DataObjects\MediaContainerData;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Exceptions\MediaBridgeFailed;
use App\Support\Exceptions\PublishingFailed;

/**
 * Traduz o post em parâmetros de container da Graph API (Seção 7.1.4),
 * expondo cada mídia pela ponte pública (Seção 7.4) — uma URL por item.
 */
class MediaContainerBuilder
{
    public function __construct(private readonly PublicMediaBridge $ponte) {}

    /**
     * Container único (imagem, vídeo/reel, story). Carrossel usa
     * carouselItem() para cada filho e carousel() para o pai.
     *
     * @throws MediaBridgeFailed|PublishingFailed
     */
    public function single(Post $post): MediaContainerData
    {
        $item = $this->firstItem($post);
        $asset = $item->mediaAsset;
        $url = $this->ponte->publish($asset);
        $legenda = $post->type->acceptsCaption() ? $post->caption : null;

        return match ($post->type) {
            PostType::FeedImage => MediaContainerData::image($url, $legenda),
            PostType::FeedVideo, PostType::Reel => MediaContainerData::reel(
                videoUrl: $url,
                caption: $legenda,
                thumbOffsetMs: $item->thumbnail_offset_ms,
            ),
            PostType::Story => $asset->isVideo()
                ? MediaContainerData::story(videoUrl: $url)
                : MediaContainerData::story(imageUrl: $url),
            PostType::Carousel => throw PublishingFailed::unexpectedState('carrossel não usa container único'),
        };
    }

    /** @throws MediaBridgeFailed */
    public function carouselItem(PostMedia $item): MediaContainerData
    {
        $asset = $item->mediaAsset;
        $url = $this->ponte->publish($asset);

        return $asset->isVideo()
            ? MediaContainerData::carouselItem(videoUrl: $url)
            : MediaContainerData::carouselItem(imageUrl: $url);
    }

    /** @param  array<int, string>  $children */
    public function carousel(Post $post, array $children): MediaContainerData
    {
        return MediaContainerData::carousel($children, $post->caption);
    }

    /**
     * Libera a cópia pública de toda mídia do post; tolera ausência. Mídia
     * que outro post em publishing ainda usa fica: a URL dela precisa
     * continuar no ar até esse outro post terminar (ou o cron a expurgar).
     */
    public function releaseAll(Post $post): void
    {
        $assetIds = $post->postMedia->pluck('media_asset_id')->filter()->unique()->values();

        if ($assetIds->isEmpty()) {
            return;
        }

        $emUsoPorOutros = PostMedia::query()
            ->whereIn('media_asset_id', $assetIds->all())
            ->where('post_id', '!=', $post->getKey())
            ->whereExists(fn ($q) => $q
                ->from('posts')
                ->whereColumn('posts.id', 'post_media.post_id')
                ->where('posts.status', PostStatus::Publishing->value)
                ->whereNull('posts.deleted_at'))
            ->pluck('media_asset_id')
            ->all();

        foreach ($post->postMedia as $item) {
            if ($item->mediaAsset === null || in_array($item->media_asset_id, $emUsoPorOutros, true)) {
                continue;
            }

            $this->ponte->release($item->mediaAsset);
        }
    }

    /** @throws PublishingFailed */
    private function firstItem(Post $post): PostMedia
    {
        $item = $post->postMedia->first();

        if ($item === null || $item->mediaAsset === null) {
            throw new PublishingFailed('O post não tem mídia anexada. Adicione a mídia e reagende.', permanent: true);
        }

        return $item;
    }
}
