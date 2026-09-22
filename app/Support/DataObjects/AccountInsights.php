<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/** Métricas de um dia da conta. Nulo = "indisponível", nunca zero inventado (Seção 14). */
final readonly class AccountInsights
{
    public function __construct(
        public ?int $reach,
        public ?int $impressions,
        public ?int $profileViews,
        public ?int $websiteClicks,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}
}
