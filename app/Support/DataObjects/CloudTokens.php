<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use Illuminate\Support\Carbon;

/** Tokens OAuth de um provedor de nuvem (Google ou Microsoft). */
final readonly class CloudTokens
{
    public function __construct(
        public string $accessToken,
        public ?Carbon $expiresAt,
        /** Nulo quando o provedor não devolveu um novo (a renovação da Google reaproveita o antigo). */
        public ?string $refreshToken = null,
        /** @var array<int, string> */
        public array $scopes = [],
    ) {}
}
