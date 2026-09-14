<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MediaFolder;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class MediaFolderPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::MediaView);
    }

    public function view(User $user, MediaFolder $model): bool
    {
        return $this->allows($user, Permission::MediaView, $model->client_id);
    }

    public function create(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::MediaUpload);
    }

    public function update(User $user, MediaFolder $model): bool
    {
        return $this->allows($user, Permission::MediaUpload, $model->client_id);
    }

    public function delete(User $user, MediaFolder $model): bool
    {
        return $this->update($user, $model);
    }

    public function restore(User $user, MediaFolder $model): bool
    {
        return $this->update($user, $model);
    }

    public function forceDelete(User $user, MediaFolder $model): bool
    {
        return $this->update($user, $model);
    }
}
