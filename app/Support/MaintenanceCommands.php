<?php

declare(strict_types=1);

namespace App\Support;

use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * Catálogo FECHADO de comandos do console de manutenção.
 *
 * Em hospedagem compartilhada sem SSH, este é o substituto do terminal. Por
 * isso ele não aceita comando digitado: só o que está listado aqui roda, com
 * argumentos fixos no código. Nada de `tinker`, nada de shell.
 */
final class MaintenanceCommands
{
    /**
     * @return array<string, array{titulo: string, descricao: string, comando: string, parametros: array<string, mixed>, perigo: bool}>
     */
    public static function all(): array
    {
        return [
            'migrate' => [
                'titulo' => 'Atualizar o banco',
                'descricao' => 'Aplica as migrations pendentes. É o primeiro passo depois de enviar uma nova versão do sistema.',
                'comando' => 'migrate',
                'parametros' => ['--force' => true],
                'perigo' => false,
            ],
            'sync-roles' => [
                'titulo' => 'Sincronizar papéis e permissões',
                'descricao' => 'Recria a matriz de papéis. Seguro de rodar quantas vezes quiser — não mexe em vínculos de usuários.',
                'comando' => 'db:seed',
                'parametros' => ['--class' => RolesAndPermissionsSeeder::class, '--force' => true],
                'perigo' => false,
            ],
            'cache-clear' => [
                'titulo' => 'Limpar os caches',
                'descricao' => 'Descarta configuração, rotas e views em cache. Rode sempre que mudar o .env.',
                'comando' => 'optimize:clear',
                'parametros' => [],
                'perigo' => false,
            ],
            'cache-build' => [
                'titulo' => 'Reconstruir os caches',
                'descricao' => 'Recompila configuração, rotas, views e eventos. Deixa o sistema mais rápido. Rode depois de limpar.',
                'comando' => 'optimize',
                'parametros' => [],
                'perigo' => false,
            ],
            'queue-drain' => [
                'titulo' => 'Processar a fila agora',
                'descricao' => 'Esvazia a fila sem esperar o cron. Útil para conferir se e-mails e publicações estão saindo.',
                'comando' => 'queue:work',
                'parametros' => ['--stop-when-empty' => true, '--max-time' => 20, '--tries' => 1],
                'perigo' => false,
            ],
            'healthcheck' => [
                'titulo' => 'Registrar batimento do agendador',
                'descricao' => 'Grava o batimento manualmente. Serve para confirmar que a aplicação escreve no banco.',
                'comando' => 'system:healthcheck',
                'parametros' => [],
                'perigo' => false,
            ],
            'backup' => [
                'titulo' => 'Fazer backup do banco agora',
                'descricao' => 'Gera um dump .sql.gz em storage/app/backups (o mesmo que roda todo dia às 02:30 UTC). O download fica em Configurações.',
                'comando' => 'backup:database',
                'parametros' => [],
                'perigo' => false,
            ],
            'schedule-run' => [
                'titulo' => 'Rodar o agendador uma vez',
                'descricao' => 'Executa o que estiver vencido no Scheduler. É exatamente o que o cron faz a cada minuto.',
                'comando' => 'schedule:run',
                'parametros' => [],
                'perigo' => false,
            ],
        ];
    }

    /** @return array{titulo: string, descricao: string, comando: string, parametros: array<string, mixed>, perigo: bool}|null */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
