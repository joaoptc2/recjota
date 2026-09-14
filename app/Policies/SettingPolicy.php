<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Setting;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class SettingPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::SettingsManage);
    }

    public function view(User $user, Setting $setting): bool
    {
        if (! $user->isAgency() || ! $this->allowsGlobally($user, Permission::SettingsManage)) {
            return false;
        }

        return $setting->client_id === null
            ? $user->seesEveryClient()
            : $this->reachesClient($user, $setting->client_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Setting $setting): bool
    {
        return $this->view($user, $setting);
    }

    public function delete(User $user, Setting $setting): bool
    {
        return $this->view($user, $setting);
    }
}
