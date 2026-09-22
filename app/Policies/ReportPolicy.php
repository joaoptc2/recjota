<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Report;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/**
 * Relatório é do cliente: quem alcança o cliente e tem reports.view baixa;
 * só a agência gera e apaga.
 */
class ReportPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $this->allowsGlobally($user, Permission::ReportsView);
    }

    public function view(User $user, Report $report): bool
    {
        return $this->allows($user, Permission::ReportsView, $report->client_id);
    }

    public function create(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::ReportsView);
    }

    public function update(User $user, Report $report): bool
    {
        return $user->isAgency() && $this->view($user, $report);
    }

    public function delete(User $user, Report $report): bool
    {
        return $this->update($user, $report);
    }

    public function restore(User $user, Report $report): bool
    {
        return $this->update($user, $report);
    }

    public function forceDelete(User $user, Report $report): bool
    {
        return $this->update($user, $report);
    }
}
