<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\HashtagSet;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class HashtagSetPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, HashtagSet $conjunto): bool
    {
        return $this->allows($user, Permission::PostsView, $conjunto->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsCreate);
    }

    public function update(User $user, HashtagSet $conjunto): bool
    {
        return $this->allows($user, Permission::PostsUpdate, $conjunto->client_id);
    }

    public function delete(User $user, HashtagSet $conjunto): bool
    {
        return $this->update($user, $conjunto);
    }
}
