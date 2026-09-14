<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aplica o escopo de tenant e preenche client_id automaticamente na criação,
 * quando há um cliente ativo no contexto.
 */
trait BelongsToClient
{
    public static function bootBelongsToClient(): void
    {
        static::addGlobalScope(new ClientScope);

        static::creating(function ($model): void {
            $key = $model->clientForeignKey();

            if ($key === $model->getKeyName() || $model->getAttribute($key) !== null) {
                return;
            }

            $active = app(TenantContext::class)->activeClientId();

            if ($active !== null) {
                $model->setAttribute($key, $active);
            }
        });
    }

    public function clientForeignKey(): string
    {
        return 'client_id';
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** Remove o escopo de tenant desta query. Use apenas em jobs e console. */
    public function scopeWithoutClientScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ClientScope::class);
    }
}
