<?php

declare(strict_types=1);

namespace App\Actions\System;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Reenfileira um job da tabela failed_jobs pelo uuid (Seção 11.2). Passa
 * pelo comando queue:retry — o mesmo caminho do console de manutenção — em
 * vez de mexer na tabela à mão.
 */
class RetryFailedJob
{
    /** @return string Saída do comando, para mostrar na tela. */
    public function __invoke(string $uuid): string
    {
        $existe = DB::table('failed_jobs')->where('uuid', $uuid)->exists();

        if (! $existe) {
            return 'Este job já saiu da lista de falhados — talvez outra pessoa o tenha reenfileirado.';
        }

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return trim(Artisan::output()) ?: 'Job devolvido à fila. Ele roda na próxima passada do cron.';
    }
}
