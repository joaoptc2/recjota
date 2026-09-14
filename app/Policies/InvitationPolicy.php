<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Invitation;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class InvitationPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::UsersInvite);
    }

    public function view(User $user, Invitation $invitation): bool
    {
        if (! $this->allowsGlobally($user, Permission::UsersInvite)) {
            return false;
        }

        // Convite da agência (sem cliente) é assunto de owner/admin.
        if ($invitation->client_id === null) {
            return $user->seesEveryClient();
        }

        return $this->reachesClient($user, $invitation->client_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Invitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }
}
