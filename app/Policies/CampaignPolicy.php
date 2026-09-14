<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class CampaignPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, Permission::PostsView, $campaign->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::CampaignsManage);
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->allows($user, Permission::CampaignsManage, $campaign->client_id);
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    public function restore(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    public function forceDelete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }
}
