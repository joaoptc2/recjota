<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SocialAccount;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Notifications\SystemAlert;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Batimento do cron (Seção 8.3 / 11.2). Grava a última execução e avisa o
 * owner por e-mail quando algo parou: cron sem bater há mais de 20 min na
 * execução anterior, jobs falhados novos ou tokens vencendo em menos de 3
 * dias. Cada tipo de alerta sai no máximo uma vez a cada 12 horas.
 */
class SystemHealthcheck extends Command
{
    public const STALE_MINUTES = 20;

    public const TOKEN_ALERT_DAYS = 3;

    public const ALERT_COOLDOWN_HOURS = 12;

    protected $signature = 'system:healthcheck';

    protected $description = 'Grava o batimento do agendador e avisa o owner quando algo parou';

    public function handle(TenantContext $tenant): int
    {
        $started = microtime(true);

        $previous = SystemHeartbeat::firstWhere('name', 'scheduler');
        $wasStale = $previous?->isStale(self::STALE_MINUTES) ?? true;

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
            $this->warn(sprintf(
                'Agendador havia parado desde %s.',
                $previous->last_run_at?->toIso8601String() ?? 'sempre',
            ));

            $this->notifyOwner(
                SystemAlert::TIPO_CRON_PARADO,
                'O cron do sistema ficou parado',
                sprintf(
                    'O agendador não bateu entre %s e %s (UTC). Nesse intervalo nada foi publicado, renovado ou enviado.',
                    $previous->last_run_at?->toIso8601String() ?? 'sempre',
                    now()->toIso8601String(),
                ),
                'Ele voltou a rodar agora, mas confira no hPanel se o cron job de 1 em 1 minuto continua cadastrado e se posts agendados nesse intervalo precisam ser reagendados.',
            );
        }

        $this->checkFailedJobs($previous);
        $tenant->withoutRestriction(fn () => $this->checkExpiringTokens());

        $this->info('Batimento registrado em '.now()->toIso8601String().' (UTC).');

        return self::SUCCESS;
    }

    /** Jobs que falharam desde o batimento anterior. */
    private function checkFailedJobs(?SystemHeartbeat $previous): void
    {
        $desde = $previous?->last_run_at;

        $novos = DB::table('failed_jobs')
            ->when($desde !== null, fn ($q) => $q->where('failed_at', '>', $desde))
            ->count();

        if ($novos === 0) {
            return;
        }

        $this->warn(sprintf('%d job(s) falharam desde o último batimento.', $novos));

        $this->notifyOwner(
            SystemAlert::TIPO_JOBS_FALHADOS,
            sprintf('%d job(s) da fila falharam', $novos),
            sprintf('%d job(s) entraram na tabela de falhados desde o último batimento. Publicações e e-mails podem ter sido afetados.', $novos),
            'Abra Configurações → Saúde do sistema para ver o erro de cada um e reenfileirar com "Tentar de novo".',
        );
    }

    private function checkExpiringTokens(): void
    {
        $contas = SocialAccount::query()
            ->with('client')
            ->connected()
            ->expiringWithin(self::TOKEN_ALERT_DAYS)
            ->orderBy('token_expires_at')
            ->get();

        if ($contas->isEmpty()) {
            return;
        }

        $lista = $contas
            ->map(fn (SocialAccount $c) => sprintf('%s (%s)', $c->handle(), $c->client?->name ?? 'sem cliente'))
            ->implode(', ');

        $this->warn(sprintf('%d token(s) vencem em menos de %d dias.', $contas->count(), self::TOKEN_ALERT_DAYS));

        $this->notifyOwner(
            SystemAlert::TIPO_TOKENS_VENCENDO,
            sprintf('%d conta(s) do Instagram param de publicar em breve', $contas->count()),
            sprintf('A renovação automática não conseguiu estender o acesso de: %s. Quando o token vencer, os posts agendados dessas contas falham.', $lista),
            'Abra Integrações e clique em "Reconectar" ao lado de cada conta, autorizando com um perfil que administre a conta no Instagram.',
        );
    }

    /** Envia ao owner, no máximo uma vez por tipo a cada 12h (Cache::add é atômico). */
    private function notifyOwner(string $tipo, string $titulo, string $mensagem, string $acao): void
    {
        if (! Cache::add('system-alert:'.$tipo, now()->toIso8601String(), now()->addHours(self::ALERT_COOLDOWN_HOURS))) {
            $this->line(sprintf('Alerta "%s" já enviado nas últimas %dh; não repetido.', $tipo, self::ALERT_COOLDOWN_HOURS));

            return;
        }

        $destinatarios = $this->owners();

        if ($destinatarios->isEmpty()) {
            $this->warn('Nenhum owner ativo para receber o alerta.');

            return;
        }

        Notification::send($destinatarios, new SystemAlert($tipo, $titulo, $mensagem, $acao, route('painel.settings')));
    }

    /** @return Collection<int, User> */
    private function owners(): Collection
    {
        return User::query()
            ->where('type', UserType::Agency->value)
            ->where('is_active', true)
            ->role(RoleName::Owner->value)
            ->get();
    }
}
