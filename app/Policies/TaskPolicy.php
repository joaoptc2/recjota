<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class TaskPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::TasksView);
    }

    public function view(User $user, Task $model): bool
    {
        return $this->allows($user, Permission::TasksView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::TasksManage);
    }

    public function update(User $user, Task $model): bool
    {
        return $this->allows($user, Permission::TasksManage, $model->client_id);
    }

    public function delete(User $user, Task $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, Task $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, Task $model): bool
    {
        return $this->update($user, $model);
    }
}
