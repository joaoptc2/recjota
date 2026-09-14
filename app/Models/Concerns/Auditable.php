<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Log de atividade imutável (Seção 6.12): quem fez o quê, quando, em qual
 * registro, com valores antes e depois.
 *
 * O model que usa este trait deve declarar $auditable com as colunas
 * relevantes. Colunas de token e senha NUNCA entram na lista.
 */
trait Auditable
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->auditable ?? [])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(class_basename($this));
    }
}
