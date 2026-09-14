<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class MediaAssetPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::MediaView);
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Permission::MediaView, $asset->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::MediaUpload);
    }

    public function update(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Permission::MediaUpload, $asset->client_id);
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $this->allows($user, Permission::MediaDelete, $asset->client_id);
    }

    public function restore(User $user, MediaAsset $asset): bool
    {
        return $this->delete($user, $asset);
    }

    public function forceDelete(User $user, MediaAsset $asset): bool
    {
        return $this->delete($user, $asset);
    }
}
