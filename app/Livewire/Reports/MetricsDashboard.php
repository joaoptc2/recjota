<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Models\Client;
use App\Models\Report;
use App\Services\Metrics\MetricsSummary;
use App\Support\DataObjects\MetricsPeriodSummary;
use App\Support\Display;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Painel de métricas de um cliente (Seção 6.8): seguidores, alcance,
 * engajamento, melhores posts, comparativo mês a mês e os PDFs gerados.
 * Serve ao painel da agência (densidade alta) e ao portal (enxuto).
 */
class MetricsDashboard extends Component
{
    public const PERIODS = ['7' => '7 dias', '30' => '30 dias', '90' => '90 dias', 'mes' => 'Mês atual'];

    public Client $client;

    public bool $portal = false;

    #[Url(as: 'periodo')]
    public string $period = '30';

    public function mount(Client $client, bool $portal = false): void
    {
        $this->authorize('view', $client);
        $this->client = $client;
        $this->portal = $portal;

        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30';
        }
    }

    public function updatedPeriod(): void
    {
        if (! array_key_exists($this->period, self::PERIODS)) {
            $this->period = '30';
        }

        unset($this->summary);
    }

    /** Cache de 5 min (driver database): a coleta é diária, e o cálculo percorre meses de linhas. */
    public const CACHE_SECONDS = 300;

    #[Computed]
    public function summary(): MetricsPeriodSummary
    {
        [$de, $ate] = $this->range();
        $chave = sprintf('metrics-summary:%d:%s:%s', $this->client->getKey(), $this->period, $ate->toDateString());

        return Cache::remember($chave, self::CACHE_SECONDS, fn () => app(MetricsSummary::class)->forClient($this->client, $de, $ate));
    }

    /** @return Collection<int, Report> */
    #[Computed]
    public function reports(): Collection
    {
        return Report::query()
            ->where('client_id', $this->client->getKey())
            ->orderByDesc('period_start')
            ->limit(12)
            ->get();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function range(): array
    {
        $hoje = Display::carbon(now(), $this->client)->endOfDay();

        if ($this->period === 'mes') {
            return [$hoje->copy()->startOfMonth()->utc(), $hoje->copy()->utc()];
        }

        $dias = (int) $this->period;

        return [$hoje->copy()->subDays($dias - 1)->startOfDay()->utc(), $hoje->copy()->utc()];
    }

    public function periodLabel(): string
    {
        [$de, $ate] = $this->range();

        return sprintf('%s a %s', display_date($de, $this->client), display_date($ate, $this->client));
    }

    /** Mês corrente e os 11 anteriores, para o formulário de PDF. @return array<string, string> */
    public function monthOptions(): array
    {
        $opcoes = [];
        $cursor = Display::carbon(now(), $this->client)->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $opcoes[$cursor->format('Y-m')] = ucfirst($cursor->locale('pt_BR')->translatedFormat('F/Y'));
            $cursor->subMonthNoOverflow();
        }

        return $opcoes;
    }

    /**
     * Polilinha SVG normalizada para a série informada (0–100 em ambos os
     * eixos). Dias sem dado deixam um buraco na linha, em vez de cair a zero.
     *
     * @param  array<int, array<string, mixed>>  $serie
     * @return array<int, string> Segmentos "x,y x,y …" (um por trecho contínuo)
     */
    public function polyline(array $serie, string $chave): array
    {
        $valores = array_map(fn (array $p) => $p[$chave], $serie);
        $conhecidos = array_filter($valores, fn ($v) => $v !== null);

        if ($conhecidos === []) {
            return [];
        }

        $max = max($conhecidos);
        $min = min($conhecidos);
        $amplitude = max(1, $max - $min);
        $n = max(1, count($valores) - 1);
        $segmentos = [];
        $atual = [];

        foreach ($valores as $i => $valor) {
            if ($valor === null) {
                if ($atual !== []) {
                    $segmentos[] = implode(' ', $atual);
                    $atual = [];
                }

                continue;
            }

            $x = round($i / $n * 100, 2);
            $y = round(100 - (($valor - $min) / $amplitude) * 90 - 5, 2);
            $atual[] = $x.','.$y;
        }

        if ($atual !== []) {
            $segmentos[] = implode(' ', $atual);
        }

        return $segmentos;
    }

    public function formatNumber(?int $valor): string
    {
        return $valor === null ? 'indisponível' : number_format($valor, 0, ',', '.');
    }

    public function formatPercent(?float $valor, bool $sinal = false): string
    {
        if ($valor === null) {
            return 'indisponível';
        }

        return ($sinal && $valor > 0 ? '+' : '').number_format($valor, 1, ',', '.').'%';
    }

    public function render(): View
    {
        return view('livewire.reports.metrics-dashboard');
    }
}
