<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Integrations\RefreshSocialAccountToken;
use App\Models\SocialAccount;
use App\Support\Enums\SocialPlatform;
use App\Support\Enums\TokenRefreshOutcome;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Renovação automática de tokens do Instagram (Seção 7.1.3).
 *
 * Roda uma vez por dia. Elegível: conta conectada cujo token vence em menos de
 * `--dias` e que foi trocado/renovado há pelo menos 24h (o Instagram recusa
 * renovar token mais novo que isso). Tokens valem 60 dias, então cada conta
 * é renovada cerca de uma vez a cada 50 dias.
 */
class RefreshSocialTokens extends Command
{
    protected $signature = 'tokens:refresh
        {--dias=10 : Renova tokens que vencem dentro desta janela}
        {--idade-minima=24 : Idade mínima do token, em horas, exigida pela API}';

    protected $description = 'Renova tokens do Instagram prestes a vencer e avisa quando não dá mais';

    public function handle(TenantContext $tenant, RefreshSocialAccountToken $renovar): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $idadeMinima = max(1, (int) $this->option('idade-minima'));

        // Comando de console não tem usuário: suspensão explícita do escopo.
        $resultados = $tenant->withoutRestriction(function () use ($dias, $idadeMinima, $renovar): array {
            $contas = SocialAccount::query()
                ->where('platform', SocialPlatform::Instagram->value)
                ->connected()
                ->expiringWithin($dias)
                ->tokenOlderThan(now()->subHours($idadeMinima))
                ->with('client')
                ->orderBy('token_expires_at')
                ->get();

            $totais = array_fill_keys(array_map(fn (TokenRefreshOutcome $o) => $o->value, TokenRefreshOutcome::cases()), 0);

            foreach ($contas as $conta) {
                $resultado = $renovar($conta);
                $totais[$resultado->value]++;

                $this->line(sprintf('%s (%s): %s', $conta->handle(), $conta->client?->name ?? 'cliente', $resultado->label()));
            }

            return $totais;
        });

        $this->info(sprintf(
            '%d renovado(s), %d expirado(s), %d adiado(s).',
            $resultados[TokenRefreshOutcome::Refreshed->value],
            $resultados[TokenRefreshOutcome::Expired->value],
            $resultados[TokenRefreshOutcome::Deferred->value],
        ));

        return self::SUCCESS;
    }
}
