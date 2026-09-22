<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\Enums\RoleName;
use DomainException;

/**
 * Desativa/reativa um usuário da agência. Usuário desativado não entra
 * (EnsureUserIsActive) e some das listas de destinatários; nada é apagado.
 *
 * Só owner mexe em owner, e o último owner ativo nunca é desativado: sem ele
 * ninguém mais consegue administrar a agência.
 */
class ToggleUserActive
{
    public function __invoke(User $alvo, User $quem): User
    {
        if ($alvo->is($quem)) {
            throw new DomainException('Você não pode desativar a própria conta. Peça a outro administrador.');
        }

        if ($alvo->is_active && $alvo->hasRole(RoleName::Owner->value)) {
            if (! $quem->hasRole(RoleName::Owner->value)) {
                throw new DomainException('Só o proprietário da agência pode desativar outro proprietário.');
            }

            $outrosOwners = User::query()
                ->whereKeyNot($alvo->getKey())
                ->where('is_active', true)
                ->role(RoleName::Owner->value)
                ->exists();

            if (! $outrosOwners) {
                throw new DomainException('Este é o único proprietário ativo. Promova outro usuário a proprietário antes de desativá-lo.');
            }
        }

        $alvo->forceFill(['is_active' => ! $alvo->is_active])->save();

        return $alvo;
    }
}
