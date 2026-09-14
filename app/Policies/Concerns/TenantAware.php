<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\Client;
use App\Models\User;
use App\Support\Enums\Permission;

/**
 * Base de toda Policy do sistema. Duas perguntas, sempre nesta ordem:
 * 1) o usuário tem acesso a ESTE cliente?  2) o papel dele permite a ação?
 *
 * Responder só à segunda é o erro que vaza dados entre tenants.
 */
trait TenantAware
{
    protected function reachesClient(User $user, Client|int|null $client): bool
    {
        if (! $user->is_active || $client === null) {
            return false;
        }

        return $user->canAccessClient($client);
    }

    protected function allows(User $user, Permission $permission, Client|int|null $client): bool
    {
        return $this->reachesClient($user, $client)
            && $user->can($permission->value);
    }

    /** Ações que não dependem de um registro específico (index, create). */
    protected function allowsGlobally(User $user, Permission $permission): bool
    {
        return $user->is_active && $user->can($permission->value);
    }
}
