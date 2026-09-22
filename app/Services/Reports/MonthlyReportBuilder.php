<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Client;
use App\Models\Report;
use App\Services\Metrics\MetricsSummary;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Relatório mensal white-label em PDF (Seção 6.8): logo e cor do cliente
 * (ou da agência), KPIs do mês, comparativo com o mês anterior, melhores
 * posts e série diária de alcance. Dompdf é PHP puro — sem wkhtmltopdf,
 * sem extensão fora da lista da hospedagem (R6).
 *
 * Um mês já gerado é regenerado no lugar (mesmo registro), para que a lista
 * do portal não acumule versões.
 */
class MonthlyReportBuilder
{
    public function __construct(private readonly MetricsSummary $metrics) {}

    public function build(Client $client, Carbon $month, ?int $generatedBy = null): Report
    {
        $inicio = $month->copy()->utc()->startOfMonth();
        $fim = $inicio->copy()->endOfMonth();
        $resumo = $this->metrics->forClient($client, $inicio, $fim);

        $html = View::make('reports.monthly', [
            'client' => $client,
            'summary' => $resumo,
            'month' => $inicio,
            'primary' => $client->primaryColor(),
            'logo' => $this->logoDataUri($client),
            'agency' => (string) config('agency.name'),
            'generatedAt' => now(),
        ])->render();

        $pdf = $this->render($html);

        $report = Report::query()
            ->withoutClientScope()
            ->where('client_id', $client->getKey())
            ->whereDate('period_start', $inicio->toDateString())
            ->first() ?? new Report(['client_id' => $client->getKey()]);

        if ($report->exists && $report->path !== null) {
            Storage::disk('local')->delete($report->path);
        }

        $caminho = sprintf('clients/%d/relatorios/%s-%s.pdf', $client->getKey(), $inicio->format('Y-m'), Str::lower(Str::random(12)));
        Storage::disk('local')->put($caminho, $pdf);

        $report->fill([
            'period_start' => $inicio->toDateString(),
            'period_end' => $fim->toDateString(),
            'path' => $caminho,
            'size_bytes' => strlen($pdf),
            'generated_by' => $generatedBy,
            'generated_at' => now(),
        ])->save();

        return $report;
    }

    /** HTML → PDF A4. Acesso remoto desligado: tudo que o PDF usa já está inline. */
    public function render(string $html): string
    {
        $opcoes = new Options;
        $opcoes->set('isRemoteEnabled', false);
        $opcoes->set('isHtml5ParserEnabled', true);
        $opcoes->set('defaultFont', 'DejaVu Sans');
        $opcoes->set('tempDir', storage_path('app/dompdf'));
        $opcoes->set('fontDir', storage_path('app/dompdf'));
        $opcoes->set('fontCache', storage_path('app/dompdf'));
        $opcoes->set('chroot', storage_path('app'));

        if (! is_dir(storage_path('app/dompdf'))) {
            @mkdir(storage_path('app/dompdf'), 0755, true);
        }

        $dompdf = new Dompdf($opcoes);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('a4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function logoDataUri(Client $client): ?string
    {
        $caminho = $client->logo_path;

        if ($caminho === null || ! Storage::disk('local')->exists($caminho)) {
            return null;
        }

        $conteudo = Storage::disk('local')->get($caminho);
        $mime = Storage::disk('local')->mimeType($caminho) ?: 'image/png';

        return sprintf('data:%s;base64,%s', $mime, base64_encode((string) $conteudo));
    }
}
