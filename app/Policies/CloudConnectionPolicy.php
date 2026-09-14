<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CloudConnection;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class CloudConnectionPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::MediaView);
    }

    public function view(User $user, CloudConnection $model): bool
    {
        return $this->allows($user, Permission::MediaView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::IntegrationsManage);
    }

    public function update(User $user, CloudConnection $model): bool
    {
        return $this->allows($user, Permission::IntegrationsManage, $model->client_id);
    }

    public function delete(User $user, CloudConnection $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, CloudConnection $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, CloudConnection $model): bool
    {
        return $this->update($user, $model);
    }
}
