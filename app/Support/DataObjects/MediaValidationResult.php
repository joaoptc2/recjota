<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

/**
 * Resultado da validação de mídia contra as regras da plataforma (Seção 7.4).
 * Erro bloqueia o agendamento; aviso apenas informa.
 */
final readonly class MediaValidationResult
{
    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public array $errors = [],
        public array $warnings = [],
    ) {}

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return ! $this->passes();
    }

    public function merge(self $outro): self
    {
        return new self(
            errors: [...$this->errors, ...$outro->errors],
            warnings: [...$this->warnings, ...$outro->warnings],
        );
    }

    public function firstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}
