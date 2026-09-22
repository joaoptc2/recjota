<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\PublishingQuota;
use App\Models\SocialAccount;
use App\Services\Integrations\SocialPublisherInterface;
use App\Support\DataObjects\PublishingLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Cota de publicação (Seção 7.1.5): 50 publicações por conta a cada 24h.
 *
 * Consultar a cota também gasta chamada, então a leitura fica em cache por
 * 5 minutos (driver database em produção). A tabela publishing_quotas é o
 * espelho local: uma linha por janela observada, atualizada a cada consulta.
 * Quando a cota está esgotada a janela começa no momento da observação — a
 * cota é deslizante e o único horário garantidamente livre é 24h depois.
 */
class PublishingQuotaGuard
{
    public const CACHE_TTL_SECONDS = 300;

    public function __construct(private readonly SocialPublisherInterface $publisher) {}

    /** Lê a cota (cache de 5 min) e atualiza o espelho local. */
    public function check(SocialAccount $conta): PublishingLimit
    {
        $limite = $this->limitFor($conta);
        $this->record($conta, $limite);

        return $limite;
    }

    /** Janela local corrente da conta (a mais recente ainda dentro de 24h). */
    public function currentWindow(SocialAccount $conta, int $duracaoSegundos = 86400): ?PublishingQuota
    {
        return PublishingQuota::query()
            ->where('social_account_id', $conta->getKey())
            ->where('window_start', '>', now()->subSeconds($duracaoSegundos))
            ->orderByDesc('window_start')
            ->first();
    }

    /**
     * Início da próxima janela livre: 24h + 1 min depois do começo da janela
     * esgotada. É para onde o post é reagendado em vez de "tentar e falhar".
     */
    public function nextFreeWindowStart(SocialAccount $conta, PublishingLimit $limite): Carbon
    {
        $janela = $this->currentWindow($conta, $limite->quotaDurationSeconds);
        $inicio = $janela?->window_start ?? now()->startOfMinute();

        return $inicio->copy()->addSeconds($limite->quotaDurationSeconds)->addMinute();
    }

    /** Uma publicação saiu: o espelho local sobe e o cache é invalidado. */
    public function consume(SocialAccount $conta): void
    {
        $janela = $this->currentWindow($conta);

        if ($janela !== null) {
            $janela->increment('used_count');
        }

        Cache::forget($this->cacheKey($conta));
    }

    public function forget(SocialAccount $conta): void
    {
        Cache::forget($this->cacheKey($conta));
    }

    private function limitFor(SocialAccount $conta): PublishingLimit
    {
        $dados = Cache::remember($this->cacheKey($conta), self::CACHE_TTL_SECONDS, function () use ($conta): array {
            $limite = $this->publisher->publishingLimit($conta);

            return [
                'quota_usage' => $limite->quotaUsage,
                'quota_total' => $limite->quotaTotal,
                'quota_duration' => $limite->quotaDurationSeconds,
            ];
        });

        return new PublishingLimit(
            quotaUsage: (int) $dados['quota_usage'],
            quotaTotal: (int) $dados['quota_total'],
            quotaDurationSeconds: (int) $dados['quota_duration'],
        );
    }

    private function record(SocialAccount $conta, PublishingLimit $limite): void
    {
        $janela = $this->currentWindow($conta, $limite->quotaDurationSeconds);

        // Esgotou: a janela que interessa começa agora, porque só daqui a 24h
        // há garantia de vaga. Uma janela esgotada já aberta é reaproveitada.
        if ($limite->isExhausted() && ($janela === null || ! $janela->isExhausted())) {
            $janela = null;
        }

        if ($janela === null) {
            $janela = PublishingQuota::query()->firstOrNew([
                'social_account_id' => $conta->getKey(),
                'window_start' => now()->startOfMinute(),
            ]);
        }

        $janela->fill([
            'used_count' => $limite->quotaUsage,
            'quota_total' => $limite->quotaTotal,
            'checked_at' => now(),
        ])->save();
    }

    private function cacheKey(SocialAccount $conta): string
    {
        return 'publishing-limit:'.$conta->getKey();
    }
}
