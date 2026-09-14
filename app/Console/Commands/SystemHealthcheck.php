<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use Illuminate\Console\Command;

/**
 * Batimento do cron (Seção 8.3). Grava a última execução e avisa quando o
 * agendador para. Sem isto, na hospedagem compartilhada um cron quebrado passa
 * dias despercebido.
 */
class SystemHealthcheck extends Command
{
    protected $signature = 'system:healthcheck';

    protected $description = 'Grava o batimento do agendador e sinaliza quando algo parou';

    public function handle(): int
    {
        $started = microtime(true);

        $previous = SystemHeartbeat::firstWhere('name', 'scheduler');
        $wasStale = $previous?->isStale() ?? true;

        SystemHeartbeat::updateOrCreate(
            ['name' => 'scheduler'],
            [
                'last_run_at' => now(),
                'last_duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'last_status' => 'ok',
                'last_message' => null,
            ],
        );

        if ($wasStale && $previous !== null) {
            // O alerta por e-mail ao owner entra junto com as notificações
            // (Fase 3). Até lá fica registrado no log, que o /health expõe.
            $this->warn(sprintf(
                'Agendador havia parado desde %s.',
                $previous->last_run_at?->toIso8601String() ?? 'sempre',
            ));
        }

        $this->info('Batimento registrado em '.now()->toIso8601String().' (UTC).');

        return self::SUCCESS;
    }
}
