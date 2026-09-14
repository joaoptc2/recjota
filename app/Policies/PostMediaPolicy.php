<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PostMedia;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/** Registro filho: a autorização segue sempre a do post pai. */
class PostMediaPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, PostMedia $item): bool
    {
        return $item->post !== null && $user->can('view', $item->post);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsUpdate);
    }

    public function update(User $user, PostMedia $item): bool
    {
        return $item->post !== null && $user->can('update', $item->post);
    }

    public function delete(User $user, PostMedia $item): bool
    {
        return $this->update($user, $item);
    }
}
