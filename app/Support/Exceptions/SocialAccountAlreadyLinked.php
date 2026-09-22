<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use App\Models\SocialAccount;
use RuntimeException;

/**
 * A mesma conta social já pertence a outro cliente da agência. Mover
 * silenciosamente misturaria histórico de posts; quem decide é um humano.
 *
 * O nome do outro cliente não aparece: quem conecta pode ser um gestor sem
 * acesso a ele, e a lista de clientes da agência não é informação sua.
 */
class SocialAccountAlreadyLinked extends RuntimeException
{
    public static function for(SocialAccount $conta): self
    {
        return new self(sprintf(
            'A conta %s já está conectada a outro cliente da agência. Peça a um administrador para desconectá-la lá antes de conectar aqui.',
            $conta->handle(),
        ));
    }
}
