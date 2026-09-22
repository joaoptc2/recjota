<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Support\Enums\ContainerStatusCode;

/** Resposta de GET /{container-id}?fields=status_code,status. */
final readonly class ContainerStatus
{
    public function __construct(
        public string $containerId,
        public ContainerStatusCode $code,
        /** Texto livre da API: em caso de ERROR traz o motivo (ex.: "Media aspect ratio..."). */
        public ?string $message = null,
    ) {}

    public function isReady(): bool
    {
        return $this->code->isReady();
    }

    public function isFailed(): bool
    {
        return $this->code->isFailed();
    }
}
