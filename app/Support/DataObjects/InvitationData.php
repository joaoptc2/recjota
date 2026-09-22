<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

use App\Support\Enums\RoleName;

/**
 * Entrada tipada de InviteUser (Seção 6.1). Já validada na apresentação; o
 * papel define o tipo do usuário (agência ou cliente) e o cliente só é
 * obrigatório para papéis de cliente.
 */
final readonly class InvitationData
{
    public function __construct(
        public string $email,
        public RoleName $role,
        public ?string $name = null,
        public ?int $clientId = null,
        public ?int $invitedBy = null,
        public int $validDays = 7,
    ) {}
}
