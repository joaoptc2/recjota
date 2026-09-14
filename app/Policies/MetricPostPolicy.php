<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MetricPost;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class MetricPostPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ReportsView);
    }

    public function view(User $user, MetricPost $metric): bool
    {
        $post = $metric->post;

        return $post !== null
            && $this->allows($user, Permission::ReportsView, $post->client_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MetricPost $metric): bool
    {
        return false;
    }

    public function delete(User $user, MetricPost $metric): bool
    {
        return false;
    }
}
