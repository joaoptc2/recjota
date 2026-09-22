<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Support\Enums\MediaContainerType;

/**
 * Parâmetros de POST /{ig-user-id}/media (Seção 7.1.4).
 *
 * A URL da mídia precisa ser pública e estável enquanto o Instagram baixa o
 * arquivo — vem da ponte de mídia (Seção 7.4), nunca do disco privado.
 */
final readonly class MediaContainerData
{
    /**
     * @param  array<int, string>  $children  IDs de containers filhos (só carrossel)
     */
    public function __construct(
        public MediaContainerType $type,
        public ?string $imageUrl = null,
        public ?string $videoUrl = null,
        public ?string $caption = null,
        public ?string $coverUrl = null,
        public ?int $thumbOffsetMs = null,
        public bool $isCarouselItem = false,
        public array $children = [],
        public ?bool $shareToFeed = null,
        public ?string $userTags = null,
        public ?string $locationId = null,
    ) {}

    public static function image(string $imageUrl, ?string $caption = null): self
    {
        return new self(type: MediaContainerType::Image, imageUrl: $imageUrl, caption: $caption);
    }

    public static function reel(string $videoUrl, ?string $caption = null, ?string $coverUrl = null, ?int $thumbOffsetMs = null, ?bool $shareToFeed = null): self
    {
        return new self(
            type: MediaContainerType::Reels,
            videoUrl: $videoUrl,
            caption: $caption,
            coverUrl: $coverUrl,
            thumbOffsetMs: $thumbOffsetMs,
            shareToFeed: $shareToFeed,
        );
    }

    public static function story(?string $imageUrl = null, ?string $videoUrl = null): self
    {
        return new self(type: MediaContainerType::Stories, imageUrl: $imageUrl, videoUrl: $videoUrl);
    }

    public static function carouselItem(?string $imageUrl = null, ?string $videoUrl = null): self
    {
        return new self(
            type: $videoUrl !== null ? MediaContainerType::Video : MediaContainerType::Image,
            imageUrl: $imageUrl,
            videoUrl: $videoUrl,
            isCarouselItem: true,
        );
    }

    /** @param  array<int, string>  $children */
    public static function carousel(array $children, ?string $caption = null): self
    {
        return new self(type: MediaContainerType::Carousel, caption: $caption, children: $children);
    }

    /**
     * Payload no formato da Graph API. Campos nulos não são enviados: a API
     * rejeita parâmetro vazio como "invalid parameter" (código 100).
     *
     * @return array<string, string|int|bool>
     */
    public function toParams(): array
    {
        $params = [];

        if ($this->type->isSentToApi()) {
            $params['media_type'] = $this->type->value;
        }

        if ($this->imageUrl !== null) {
            $params['image_url'] = $this->imageUrl;
        }

        if ($this->videoUrl !== null) {
            $params['video_url'] = $this->videoUrl;
        }

        if ($this->caption !== null && $this->caption !== '') {
            $params['caption'] = $this->caption;
        }

        if ($this->coverUrl !== null) {
            $params['cover_url'] = $this->coverUrl;
        }

        if ($this->thumbOffsetMs !== null) {
            $params['thumb_offset'] = $this->thumbOffsetMs;
        }

        if ($this->isCarouselItem) {
            $params['is_carousel_item'] = true;
        }

        if ($this->children !== []) {
            $params['children'] = implode(',', $this->children);
        }

        if ($this->shareToFeed !== null) {
            $params['share_to_feed'] = $this->shareToFeed;
        }

        if ($this->userTags !== null) {
            $params['user_tags'] = $this->userTags;
        }

        if ($this->locationId !== null) {
            $params['location_id'] = $this->locationId;
        }

        return $params;
    }
}
