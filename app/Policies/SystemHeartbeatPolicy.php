<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/** Saúde do sistema: agência apenas, nunca exposta no portal do cliente. */
class SystemHeartbeatPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::SettingsManage);
    }

    public function view(User $user, SystemHeartbeat $heartbeat): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SystemHeartbeat $heartbeat): bool
    {
        return false;
    }

    public function delete(User $user, SystemHeartbeat $heartbeat): bool
    {
        return false;
    }
}
