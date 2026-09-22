<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/** Quem autorizou a conexão de nuvem, como o provedor a identifica. */
final readonly class CloudProfile
{
    public function __construct(
        public string $id,
        public ?string $email,
        public ?string $name,
    ) {}
}
