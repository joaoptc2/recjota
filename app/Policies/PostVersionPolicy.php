<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PostVersion;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/** Histórico imutável: leitura segue o post, escrita e exclusão nunca. */
class PostVersionPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::PostsView);
    }

    public function view(User $user, PostVersion $version): bool
    {
        return $version->post !== null && $user->can('view', $version->post);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PostVersion $version): bool
    {
        return false;
    }

    public function delete(User $user, PostVersion $version): bool
    {
        return false;
    }
}
