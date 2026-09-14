<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Approval;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class ApprovalPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, Approval $approval): bool
    {
        return $this->allows($user, Permission::PostsView, $approval->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ApprovalsRequest);
    }

    public function update(User $user, Approval $approval): bool
    {
        return $this->allows($user, Permission::ApprovalsDecide, $approval->client_id);
    }

    public function delete(User $user, Approval $approval): bool
    {
        return $this->allows($user, Permission::ApprovalsRequest, $approval->client_id);
    }

    public function decide(User $user, Approval $approval): bool
    {
        return $this->update($user, $approval);
    }
}
