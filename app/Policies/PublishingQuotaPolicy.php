<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PublishingQuota;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/** Dado operacional: só a agência com permissão de integrações enxerga. */
class PublishingQuotaPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::IntegrationsManage);
    }

    public function view(User $user, PublishingQuota $quota): bool
    {
        return $quota->socialAccount !== null && $user->can('view', $quota->socialAccount);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PublishingQuota $quota): bool
    {
        return false;
    }

    public function delete(User $user, PublishingQuota $quota): bool
    {
        return false;
    }
}
