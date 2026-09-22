<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/**
 * Resposta de GET /{ig-user-id}/content_publishing_limit (Seção 7.1.5).
 * quota_usage = publicações nas últimas 24h; quota_total = teto (hoje 50).
 */
final readonly class PublishingLimit
{
    public function __construct(
        public int $quotaUsage,
        public int $quotaTotal,
        /** Janela da cota em segundos (86400). */
        public int $quotaDurationSeconds,
    ) {}

    public function remaining(): int
    {
        return max(0, $this->quotaTotal - $this->quotaUsage);
    }

    public function isExhausted(): bool
    {
        return $this->remaining() === 0;
    }
}
