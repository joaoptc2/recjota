<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use App\Policies\Concerns\TenantAware;
use App\Support\Enums\Permission;

/**
 * Conta social é objeto técnico: o portal do cliente não expõe nenhuma tela de
 * token ou integração (Seção 6.10). Por isso todo acesso exige um usuário da
 * agência com permissão de integrações.
 */
class SocialAccountPolicy
{
    use TenantAware;

    public function viewAny(User $user): bool
    {
        return $user->isAgency() && $this->allowsGlobally($user, Permission::IntegrationsManage);
    }

    public function view(User $user, SocialAccount $account): bool
    {
        return $user->isAgency() && $this->allows($user, Permission::IntegrationsManage, $account->client_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, SocialAccount $account): bool
    {
        return $this->view($user, $account);
    }

    public function delete(User $user, SocialAccount $account): bool
    {
        return $this->view($user, $account);
    }

    public function restore(User $user, SocialAccount $account): bool
    {
        return $this->view($user, $account);
    }

    public function forceDelete(User $user, SocialAccount $account): bool
    {
        return $this->view($user, $account);
    }

    public function reconnect(User $user, SocialAccount $account): bool
    {
        return $this->view($user, $account);
    }
}
