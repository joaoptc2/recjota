<?php

declare(strict_types=1);

namespace App\Jobs\Metrics;

use App\Models\MetricAccountDaily;
use App\Models\Scopes\ClientScope;
use App\Models\SocialAccount;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\SocialInsightsInterface;
use App\Support\Enums\ConnectionStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Coleta as métricas de um dia de uma conta (Seção 6.8). Idempotente: a
 * linha é única por conta + data, então rodar de novo atualiza em vez de
 * duplicar. Uma conta com token revogado é marcada e o job termina sem
 * relançar — o motor de publicação já avisa o gestor.
 */
class SyncAccountMetricsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $socialAccountId,
        public readonly string $date,
    ) {}

    public function handle(TenantContext $tenant, SocialInsightsInterface $insights): void
    {
        $tenant->withoutRestriction(function () use ($insights): void {
            $conta = SocialAccount::query()->withoutGlobalScope(ClientScope::class)->find($this->socialAccountId);

            if ($conta === null || $conta->needsReconnection()) {
                return;
            }

            $dia = Carbon::parse($this->date, 'UTC')->startOfDay();

            try {
                $snapshot = $insights->accountSnapshot($conta);
                $diario = $insights->accountDailyInsights($conta, $dia);
            } catch (InstagramApiException $e) {
                $this->handleFailure($conta, $e);

                return;
            }

            // whereDate: a coluna é DATE, mas o SQLite dos testes compara
            // strings e o cast grava com hora zerada.
            $linha = MetricAccountDaily::query()
                ->where('social_account_id', $conta->getKey())
                ->whereDate('date', $dia->toDateString())
                ->first() ?? new MetricAccountDaily(['social_account_id' => $conta->getKey(), 'date' => $dia->toDateString()]);

            $linha->fill([
                'followers' => $snapshot->followers,
                'follows' => $snapshot->follows,
                'reach' => $diario->reach,
                'impressions' => $diario->impressions,
                'profile_views' => $diario->profileViews,
                'website_clicks' => $diario->websiteClicks,
                'raw' => ['snapshot' => $snapshot->raw] + $diario->raw,
            ])->save();

            $conta->forceFill(['last_synced_at' => now()])->save();
        });
    }

    private function handleFailure(SocialAccount $conta, InstagramApiException $e): void
    {
        if ($e->isTokenInvalid() || $e->isPermissionDenied()) {
            $conta->fill([
                'connection_status' => ConnectionStatus::Expired,
                'last_error' => $e->actionableMessage(),
            ])->save();
        }

        Log::warning('Métricas: coleta da conta falhou', [
            'conta' => $conta->handle(),
            'client_id' => $conta->client_id,
            'dia' => $this->date,
            'permanente' => $e->isPermanent(),
            'erro' => $e->getMessage(),
        ]);
    }
}
