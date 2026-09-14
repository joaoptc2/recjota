<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class UserPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::UsersManage)
            || $this->allowsGlobally($user, Permission::UsersInvite);
    }

    /**
     * Usuário do cliente só enxerga colegas do próprio time; usuário da agência
     * enxerga quem compartilha ao menos um cliente com ele.
     */
    public function view(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        if (! $user->is_active) {
            return false;
        }

        if ($user->seesEveryClient()) {
            return true;
        }

        if ($user->isClient() && $target->isAgency()) {
            return false;
        }

        return array_intersect($user->accessibleClientIds(), $target->accessibleClientIds()) !== [];
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::UsersInvite);
    }

    public function update(User $user, User $target): bool
    {
        if ($user->is($target)) {
            return true;
        }

        return $this->allowsGlobally($user, Permission::UsersManage)
            && $this->view($user, $target);
    }

    public function delete(User $user, User $target): bool
    {
        return ! $user->is($target)
            && $this->allowsGlobally($user, Permission::UsersManage)
            && $this->view($user, $target);
    }

    public function restore(User $user, User $target): bool
    {
        return $this->delete($user, $target);
    }

    public function forceDelete(User $user, User $target): bool
    {
        return $this->delete($user, $target);
    }
}
