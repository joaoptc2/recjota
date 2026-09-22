<?php

declare(strict_types=1);

namespace App\Jobs\Reports;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Notifications\MonthlyReportReady;
use App\Services\Reports\MonthlyReportBuilder;
use App\Services\Reports\ReportRecipients;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Gera o PDF de um mês para um cliente e, quando pedido, envia por e-mail
 * (Seção 6.8). Um job por cliente: o PDF leva alguns segundos e a fila
 * roda em janelas curtas (R5).
 */
class GenerateMonthlyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $clientId,
        public readonly string $month,
        public readonly bool $send = false,
    ) {}

    public function handle(TenantContext $tenant, MonthlyReportBuilder $builder, ReportRecipients $recipients): void
    {
        $tenant->withoutRestriction(function () use ($builder, $recipients): void {
            $client = Client::query()->withoutGlobalScope(ClientScope::class)->find($this->clientId);

            if ($client === null) {
                return;
            }

            $report = $builder->build($client, Carbon::parse($this->month.'-01', 'UTC'));

            Log::info('Relatório mensal gerado', [
                'client_id' => $client->getKey(),
                'mes' => $this->month,
                'bytes' => $report->size_bytes,
            ]);

            if (! $this->send) {
                return;
            }

            $destinatarios = $recipients->forClient($client);

            if ($destinatarios->isEmpty()) {
                Log::warning('Relatório mensal sem destinatário', ['client_id' => $client->getKey(), 'mes' => $this->month]);

                return;
            }

            Notification::send($destinatarios, new MonthlyReportReady($report->setRelation('client', $client)));
            $report->forceFill(['sent_at' => now()])->save();
        });
    }
}
