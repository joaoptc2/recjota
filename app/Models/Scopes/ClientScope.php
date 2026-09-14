<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global Scope de multi-tenancy (Seção 4.1). Aplicado por BelongsToClient a
 * todo model que carrega client_id.
 */
final class ClientScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(TenantContext::class);

        if ($tenant->isSuppressed()) {
            return;
        }

        if ($tenant->isUnrestricted()) {
            $this->applyActiveClientFilter($builder, $model, $tenant);

            return;
        }

        $column = $model->qualifyColumn($model->clientForeignKey());
        $allowed = $tenant->allowedClientIds();

        if ($allowed === []) {
            // Usuário sem nenhum cliente vinculado não enxerga nada.
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($column, $allowed);

        $this->applyActiveClientFilter($builder, $model, $tenant);
    }

    private function applyActiveClientFilter(Builder $builder, Model $model, TenantContext $tenant): void
    {
        $active = $tenant->activeClientId();

        if ($active === null) {
            return;
        }

        $builder->where($model->qualifyColumn($model->clientForeignKey()), $active);
    }
}
