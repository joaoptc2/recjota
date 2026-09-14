<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class ClientPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ClientsView);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsView, $client);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ClientsCreate);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsUpdate, $client);
    }

    /** Exclusão de cliente é exclusiva do owner (Seção 4.2). */
    public function delete(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsDelete, $client);
    }

    public function restore(User $user, Client $client): bool
    {
        return $this->delete($user, $client);
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return $this->delete($user, $client);
    }

    public function manageTeam(User $user, Client $client): bool
    {
        return $this->allows($user, Permission::ClientsManageTeam, $client)
            || $this->allows($user, Permission::UsersManage, $client);
    }
}
