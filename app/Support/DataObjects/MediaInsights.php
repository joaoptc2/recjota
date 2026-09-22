<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/** Métricas de uma mídia publicada. Nulo = "indisponível". */
final readonly class MediaInsights
{
    public function __construct(
        public ?int $reach,
        public ?int $impressions,
        public ?int $likes,
        public ?int $comments,
        public ?int $saves,
        public ?int $shares,
        public ?int $videoViews,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}

    /** Engajamento em % do alcance: (curtidas + comentários + salvos + compartilhamentos) / alcance. */
    public function engagementRate(): ?float
    {
        if ($this->reach === null || $this->reach <= 0) {
            return null;
        }

        $interacoes = ($this->likes ?? 0) + ($this->comments ?? 0) + ($this->saves ?? 0) + ($this->shares ?? 0);

        return round($interacoes / $this->reach * 100, 4);
    }
}
