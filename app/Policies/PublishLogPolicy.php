<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PublishLog;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/** Histórico técnico de publicação: só a agência com integrations.manage lê; ninguém edita. */
class PublishLogPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::IntegrationsManage);
    }

    public function view(User $user, PublishLog $log): bool
    {
        return $user->isAgency()
            && $this->allows($user, Permission::IntegrationsManage, $log->post?->client_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PublishLog $log): bool
    {
        return false;
    }

    public function delete(User $user, PublishLog $log): bool
    {
        return false;
    }
}
