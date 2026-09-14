<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Motor de agendamento sem worker (Seção 8)
|------------------------------------------------------------------------------
| Um único cron no hPanel chama o agendador a cada minuto — por `cron.sh` (tipo
| Custom) ou por `cron.php` (tipo PHP). Tudo o mais é orquestrado aqui, de modo
| a funcionar mesmo em planos que limitam o número de cron jobs (R4).
|
| Por que Schedule::call() e não Schedule::command():
| `Schedule::command()` abre um processo filho via proc_open, e boa parte das
| hospedagens compartilhadas desabilita essa função. Rodando dentro do mesmo
| processo, o agendador funciona em qualquer plano — a troca é abrir mão do
| isolamento entre comandos, o que é aceitável porque toda execução é curta
| (R5) e todo job é idempotente.
|
| Regras que valem para TODO agendamento abaixo:
|  - withoutOverlapping() com expiração: hospedagem compartilhada mata
|    processos, e lock órfão trava o sistema inteiro (R1 / Seção 8.3);
|  - nenhuma execução longa: a fila é drenada em janelas curtas (R5);
|  - todo job é idempotente — reexecutar não pode duplicar publicação.
*/

// Drena a fila em janelas de 45s, encerrando antes do minuto seguinte.
// tries=1 de propósito: o retry de publicação é regra de negócio, visível ao
// usuário, e não o mecanismo da fila (Seção 8.3).
Schedule::call(fn () => Artisan::call('queue:work', [
    '--stop-when-empty' => true,
    '--max-time' => 45,
    '--tries' => 1,
    '--sleep' => 1,
]))
    ->name('fila-drenar')
    ->everyMinute()
    ->withoutOverlapping(5);

// Batimento: se parar, /health e o console de manutenção denunciam.
Schedule::call(fn () => Artisan::call('system:healthcheck'))
    ->name('batimento')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

/*
| Os agendamentos abaixo entram junto com as fases que os criam:
|   Fase 3  approvals:remind          (hourly)
|   Fase 4  posts:dispatch-due        (everyMinute)
|   Fase 4  instagram:check-containers(everyMinute)
|   Fase 4  tokens:refresh            (dailyAt 03:00)
|   Fase 4  media:cleanup-temp        (hourly)
|   Fase 6  metrics:sync-accounts     (dailyAt 04:10)
|   Fase 6  metrics:sync-posts        (dailyAt 04:40)
|   Fase 6  reports:monthly           (monthlyOn 1, 09:00)
*/
