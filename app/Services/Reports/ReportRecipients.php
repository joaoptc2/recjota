<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use Illuminate\Database\Eloquent\Collection;

/**
 * Quem recebe o relatório mensal: os administradores do cliente no portal e
 * os gestores da agência vinculados a ele. Usuário desativado não recebe.
 */
class ReportRecipients
{
    /** @return Collection<int, User> */
    public function forClient(Client $client): Collection
    {
        return $client->users()
            ->where('users.is_active', true)
            ->where(function ($q): void {
                $q->where(fn ($q) => $q->where('users.type', UserType::Client->value)->where('client_user.role', RoleName::ClientAdmin->value))
                    ->orWhere(fn ($q) => $q->where('users.type', UserType::Agency->value)->where('client_user.role', RoleName::Gestor->value));
            })
            ->get();
    }
}
