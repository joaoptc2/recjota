<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Reports\GenerateMonthlyReportJob;
use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Support\Enums\ClientStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dia 1 de cada mês (Seção 6.8): enfileira o relatório do mês anterior para
 * cada cliente ativo com conta conectada. Um job por cliente; o e-mail sai
 * de dentro do job, com o PDF anexo.
 */
class SendMonthlyReports extends Command
{
    protected $signature = 'reports:monthly
        {--mes= : Mês a gerar (AAAA-MM); padrão: o mês anterior}
        {--sem-envio : Só gera o PDF, sem e-mail}';

    protected $description = 'Gera e envia o relatório mensal em PDF de cada cliente';

    public function handle(TenantContext $tenant): int
    {
        $mes = $this->option('mes') !== null
            ? Carbon::parse((string) $this->option('mes').'-01', 'UTC')
            : now()->subMonthNoOverflow()->startOfMonth();
        $enviar = ! $this->option('sem-envio');

        $total = $tenant->withoutRestriction(function () use ($mes, $enviar): int {
            $clientes = Client::query()
                ->withoutGlobalScope(ClientScope::class)
                ->where('status', ClientStatus::Active->value)
                ->whereHas('socialAccounts')
                ->orderBy('id')
                ->get();

            foreach ($clientes as $cliente) {
                GenerateMonthlyReportJob::dispatch($cliente->getKey(), $mes->format('Y-m'), $enviar);
                $this->line(sprintf('%s: relatório de %s enfileirado%s.', $cliente->name, $mes->format('m/Y'), $enviar ? ' com envio' : ''));
            }

            return $clientes->count();
        });

        $this->info(sprintf('%d relatório(s) enfileirado(s) para %s.', $total, $mes->format('m/Y')));

        return self::SUCCESS;
    }
}
