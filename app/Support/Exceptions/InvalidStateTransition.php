<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Support\Enums\PostStatus;
use DomainException;

/**
 * Lançada quando alguém tenta mover um post por uma transição que a máquina de
 * estados (Seção 5) não permite. É uma exceção de domínio: nunca deve ser
 * capturada silenciosamente, e nunca deve virar 500 sem mensagem acionável.
 */
final class InvalidStateTransition extends DomainException
{
    public function __construct(
        public readonly PostStatus $from,
        public readonly PostStatus $to,
    ) {
        parent::__construct(sprintf(
            'Transição de status ilegal: %s → %s.',
            $from->value,
            $to->value,
        ));
    }

    public static function between(PostStatus $from, PostStatus $to): self
    {
        return new self($from, $to);
    }
}
