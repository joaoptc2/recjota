<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/** Contadores atuais da conta (followers_count etc.). Nulo = a API não informou. */
final readonly class AccountSnapshot
{
    public function __construct(
        public ?int $followers,
        public ?int $follows,
        public ?int $mediaCount,
        /** @var array<string, mixed> */
        public array $raw = [],
    ) {}
}
