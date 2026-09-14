<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClientSetting;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class ClientSettingPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ClientsView);
    }

    public function view(User $user, ClientSetting $model): bool
    {
        return $this->allows($user, Permission::ClientsView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ClientsUpdate);
    }

    public function update(User $user, ClientSetting $model): bool
    {
        return $this->allows($user, Permission::ClientsUpdate, $model->client_id);
    }

    public function delete(User $user, ClientSetting $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, ClientSetting $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, ClientSetting $model): bool
    {
        return $this->update($user, $model);
    }
}
