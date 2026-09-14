<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class CalendarEventPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, CalendarEvent $model): bool
    {
        return $this->allows($user, Permission::PostsView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::CalendarManage);
    }

    public function update(User $user, CalendarEvent $model): bool
    {
        return $this->allows($user, Permission::CalendarManage, $model->client_id);
    }

    public function delete(User $user, CalendarEvent $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, CalendarEvent $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, CalendarEvent $model): bool
    {
        return $this->update($user, $model);
    }
}
