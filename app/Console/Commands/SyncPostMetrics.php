<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Metrics\SyncPostMetricsJob;
use App\Models\Post;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\PostStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Enfileira a coleta de métricas dos posts publicados nos últimos N dias
 * (Seção 6.8). Um job por post (duas chamadas cada). O limite por execução
 * protege a cota horária da API quando há muitos clientes.
 */
class SyncPostMetrics extends Command
{
    protected $signature = 'metrics:sync-posts
        {--dias=30 : Coleta posts publicados dentro desta janela}
        {--limite=200 : Máximo de posts enfileirados por execução}';

    protected $description = 'Enfileira a coleta das métricas dos posts publicados recentemente';

    public function handle(TenantContext $tenant): int
    {
        $dias = max(1, (int) $this->option('dias'));
        $limite = max(1, (int) $this->option('limite'));

        $total = $tenant->withoutRestriction(function () use ($dias, $limite): int {
            $posts = Post::query()
                ->withoutClientScope()
                ->where('status', PostStatus::Published->value)
                ->whereNotNull('external_post_id')
                ->where('published_at', '>=', now()->subDays($dias))
                ->whereHas('socialAccount', fn ($q) => $q->where('connection_status', ConnectionStatus::Connected->value))
                ->orderByDesc('published_at')
                ->limit($limite)
                ->pluck('id');

            foreach ($posts as $id) {
                SyncPostMetricsJob::dispatch((int) $id);
            }

            return $posts->count();
        });

        $this->info(sprintf('%d post(s) enfileirado(s) para coleta.', $total));

        return self::SUCCESS;
    }
}
