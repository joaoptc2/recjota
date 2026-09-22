<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Report;
use App\Services\Reports\CsvExporter;
use App\Services\Reports\MonthlyReportBuilder;
use App\Support\Display;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Throwable;

/**
 * Relatórios da agência (Seção 6.8): painel por cliente, CSV e PDF sob
 * demanda. O portal tem a própria versão, enxuta, em Portal\ReportController.
 */
class ReportController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $this->authorize('viewAny', Report::class);

        $clientes = Client::query()->orderBy('name')->get();

        if ($clientes->isEmpty()) {
            return redirect()->route('painel.clients.index')
                ->withErrors(['client' => 'Você precisa de ao menos um cliente atribuído para ver relatórios.']);
        }

        $cliente = $request->filled('cliente')
            ? $clientes->firstWhere('ulid', $request->string('cliente')->toString())
            : $clientes->first();

        abort_if($cliente === null, 404);

        return view('agency.reports', ['client' => $cliente, 'clients' => $clientes]);
    }

    /** CSV do período (padrão: últimos 30 dias no fuso do cliente). */
    public function export(Request $request, Client $client, CsvExporter $csv): Response
    {
        $this->authorize('view', $client);
        $this->authorize('viewAny', Report::class);

        [$de, $ate] = $this->period($request, $client);

        $conteudo = $csv->forClient($client, $de, $ate);
        $nome = sprintf('metricas-%s-%s-a-%s.csv', str($client->name)->slug(), $de->format('Y-m-d'), $ate->format('Y-m-d'));

        return response($conteudo, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$nome.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Gera (ou regenera) o PDF de um mês agora mesmo. */
    public function generate(Request $request, Client $client, MonthlyReportBuilder $builder): RedirectResponse
    {
        $this->authorize('view', $client);
        $this->authorize('create', Report::class);

        $dados = $request->validate(['mes' => ['required', 'date_format:Y-m']]);
        $mes = Carbon::createFromFormat('Y-m', $dados['mes'], 'UTC')->startOfMonth();

        if ($mes->greaterThan(now()->startOfMonth())) {
            return back()->withErrors(['mes' => 'Esse mês ainda não começou. Escolha o mês atual ou um anterior.']);
        }

        try {
            $report = $builder->build($client, $mes, $request->user()?->getKey());
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['mes' => 'Não foi possível gerar o PDF agora. Tente de novo; se persistir, fale com o suporte.']);
        }

        return redirect()
            ->route('painel.reports', ['cliente' => $client->ulid])
            ->with('status', sprintf('Relatório de %s gerado (%s).', $report->periodLabel(), $this->humanSize($report->size_bytes)));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request, Client $client): array
    {
        $dias = (int) $request->integer('dias', 30);
        $dias = in_array($dias, [7, 30, 90], true) ? $dias : 30;

        if ($request->filled('mes') && preg_match('/^\d{4}-\d{2}$/', (string) $request->string('mes')) === 1) {
            $inicio = Carbon::createFromFormat('Y-m', (string) $request->string('mes'), $client->displayTimezone())->startOfMonth();

            return [$inicio->copy()->utc(), $inicio->copy()->endOfMonth()->utc()];
        }

        $hoje = Display::carbon(now(), $client)->endOfDay();

        return [$hoje->copy()->subDays($dias - 1)->startOfDay()->utc(), $hoje->utc()];
    }

    private function humanSize(?int $bytes): string
    {
        if ($bytes === null) {
            return 'tamanho indisponível';
        }

        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
