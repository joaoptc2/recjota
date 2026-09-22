<?php

declare(strict_types=1);

namespace App\Livewire\Integrations;

use App\Models\Post;
use App\Models\PublishingQuota;
use App\Models\PublishLog;
use App\Models\Scopes\ClientScope;
use App\Models\SocialAccount;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Saúde das integrações (Seção 6.12): uma linha por conta conectada, com
 * semáforo, validade do token, cota da janela atual, último erro e o
 * histórico técnico de publicação. O ClientScope já limita às contas dos
 * clientes que o usuário alcança.
 */
class IntegrationHealth extends Component
{
    /** Dias de validade abaixo dos quais o token ganha destaque. */
    public const DIAS_ALERTA = 7;

    public const MAX_LOGS = 20;

    /** Conta com o histórico de publicação aberto. */
    public ?int $logsDe = null;

    #[Computed]
    public function accounts(): Collection
    {
        $contas = SocialAccount::query()
            ->with('client')
            ->get()
            ->sortBy(fn (SocialAccount $c) => [$c->client?->name, $c->username])
            ->values();

        // Cota mais recente por conta em uma consulta só.
        $cotas = PublishingQuota::query()
            ->whereIn('social_account_id', $contas->modelKeys())
            ->orderByDesc('window_start')
            ->get()
            ->unique('social_account_id')
            ->keyBy('social_account_id');

        foreach ($contas as $conta) {
            $conta->setRelation('latestQuota', $cotas->get($conta->getKey()));
        }

        return $contas;
    }

    public function toggleLogs(int $accountId): void
    {
        if ($this->logsDe === $accountId) {
            $this->logsDe = null;

            return;
        }

        // Fora do escopo de tenant de propósito: a negativa vem da Policy (403).
        $conta = SocialAccount::query()->withoutGlobalScope(ClientScope::class)->findOrFail($accountId);
        $this->authorize('view', $conta);
        $this->authorize('viewAny', PublishLog::class);

        $this->logsDe = $accountId;
    }

    /** @return Collection<int, PublishLog> */
    #[Computed]
    public function logs(): Collection
    {
        if ($this->logsDe === null) {
            return new Collection;
        }

        return PublishLog::query()
            ->with('post')
            ->whereIn('post_id', Post::query()->where('social_account_id', $this->logsDe)->select('id'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_LOGS)
            ->get();
    }

    /** Classe do ponto do semáforo (verde/âmbar/vermelho). */
    public function lightClass(SocialAccount $conta): string
    {
        return match ($conta->connection_status?->trafficLight()) {
            'green' => 'bg-emerald-500',
            'amber' => 'bg-amber-500',
            default => 'bg-rose-500',
        };
    }

    public function render(): View
    {
        return view('livewire.integrations.integration-health');
    }
}
