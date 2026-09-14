<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Brief;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class BriefPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::BriefsView);
    }

    public function view(User $user, Brief $model): bool
    {
        return $this->allows($user, Permission::BriefsView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::BriefsCreate);
    }

    public function update(User $user, Brief $model): bool
    {
        return $this->allows($user, Permission::BriefsManage, $model->client_id);
    }

    public function delete(User $user, Brief $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, Brief $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, Brief $model): bool
    {
        return $this->update($user, $model);
    }
}
