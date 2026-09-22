<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use Illuminate\Support\Carbon;

/**
 * Token devolvido pelos endpoints de OAuth do Instagram. Curto (1h) ou longo
 * (60 dias) — a diferença está só em expiresAt.
 */
final readonly class InstagramTokens
{
    public function __construct(
        public string $accessToken,
        public ?Carbon $expiresAt,
        /** ID da conta (só o endpoint de troca de code informa). */
        public ?string $userId = null,
        /** @var array<int, string> Permissões concedidas (só na troca de code). */
        public array $permissions = [],
    ) {}
}
