<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Chave pública ULID. Nenhuma URL do sistema expõe id incremental (Seção 14).
 */
trait HasUlidKey
{
    public static function bootHasUlidKey(): void
    {
        static::creating(function ($model): void {
            if (blank($model->ulid)) {
                $model->ulid = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
