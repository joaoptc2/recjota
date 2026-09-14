<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
|------------------------------------------------------------------------------
| Motor de agendamento sem worker (Seção 8)
|------------------------------------------------------------------------------
| Um único cron no hPanel chama `php artisan schedule:run` a cada minuto. Tudo
| o mais é orquestrado aqui, de modo a funcionar mesmo em planos que limitam o
| número de cron jobs (R4).
|
| Regras que valem para TODO comando registrado abaixo:
|  - withoutOverlapping() com expiração: hospedagem compartilhada mata
|    processos, e lock órfão trava o sistema inteiro (R1 / Seção 8.3);
|  - nenhuma execução longa: a fila é drenada em janelas curtas (R5);
|  - todo job é idempotente — reexecutar não pode duplicar publicação.
*/

// Drena a fila em janelas de 50s, encerrando antes do minuto seguinte.
// tries=1 de propósito: o retry de publicação é regra de negócio, visível ao
// usuário, e não o mecanismo da fila (Seção 8.3).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

// Batimento: se parar, /health denuncia em até 20 minutos.
Schedule::command('system:healthcheck')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10);

/*
| Os comandos abaixo entram junto com as fases que os criam:
|   Fase 3  approvals:remind          (hourly)
|   Fase 4  posts:dispatch-due        (everyMinute)
|   Fase 4  instagram:check-containers(everyMinute)
|   Fase 4  tokens:refresh            (dailyAt 03:00)
|   Fase 4  media:cleanup-temp        (hourly)
|   Fase 6  metrics:sync-accounts     (dailyAt 04:10)
|   Fase 6  metrics:sync-posts        (dailyAt 04:40)
|   Fase 6  reports:monthly           (monthlyOn 1, 09:00)
*/
