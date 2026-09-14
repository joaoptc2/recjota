<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ApprovalLink;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class ApprovalLinkPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ApprovalsRequest);
    }

    public function view(User $user, ApprovalLink $link): bool
    {
        return $this->allows($user, Permission::ApprovalsRequest, $link->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ApprovalsRequest);
    }

    public function update(User $user, ApprovalLink $link): bool
    {
        return $this->allows($user, Permission::ApprovalsRequest, $link->client_id);
    }

    public function delete(User $user, ApprovalLink $link): bool
    {
        return $this->update($user, $link);
    }

    public function revoke(User $user, ApprovalLink $link): bool
    {
        return $this->update($user, $link);
    }
}
