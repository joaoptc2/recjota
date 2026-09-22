<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Metrics\SyncAccountMetricsJob;
use App\Models\SocialAccount;
use App\Support\Enums\SocialPlatform;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Enfileira a coleta diária de métricas de conta (Seção 6.8). Um job por
 * conta conectada: cada um faz três chamadas curtas e a fila os drena nas
 * janelas de 45s (R5). Por padrão coleta o dia de ontem (UTC), o único já
 * fechado.
 */
class SyncAccountMetrics extends Command
{
    protected $signature = 'metrics:sync-accounts {--dia= : Dia a coletar (AAAA-MM-DD, UTC); padrão ontem}';

    protected $description = 'Enfileira a coleta das métricas diárias de cada conta do Instagram conectada';

    public function handle(TenantContext $tenant): int
    {
        $dia = $this->option('dia') !== null
            ? Carbon::parse((string) $this->option('dia'), 'UTC')->startOfDay()
            : now()->subDay()->startOfDay();

        $total = $tenant->withoutRestriction(function () use ($dia): int {
            $contas = SocialAccount::query()
                ->withoutClientScope()
                ->where('platform', SocialPlatform::Instagram->value)
                ->connected()
                ->orderBy('id')
                ->get();

            foreach ($contas as $conta) {
                SyncAccountMetricsJob::dispatch($conta->getKey(), $dia->toDateString());
                $this->line(sprintf('%s: coleta de %s enfileirada.', $conta->handle(), $dia->toDateString()));
            }

            return $contas->count();
        });

        $this->info(sprintf('%d conta(s) enfileirada(s) para %s.', $total, $dia->toDateString()));

        return self::SUCCESS;
    }
}
