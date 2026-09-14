<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MetricAccountDaily;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

class MetricAccountDailyPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ReportsView);
    }

    public function view(User $user, MetricAccountDaily $metric): bool
    {
        $account = $metric->socialAccount;

        return $account !== null
            && $this->allows($user, Permission::ReportsView, $account->client_id);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, MetricAccountDaily $metric): bool
    {
        return false;
    }

    public function delete(User $user, MetricAccountDaily $metric): bool
    {
        return false;
    }
}
