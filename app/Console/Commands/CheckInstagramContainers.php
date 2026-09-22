<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Models\Post;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Rede de segurança do polling de container (Seção 8). O
 * CheckContainerStatusJob reagenda a si mesmo com delay, mas numa fila
 * drenada por cron um job atrasado pode se perder (worker morto pela
 * hospedagem, fila reiniciada). Este comando reenfileira a checagem de todo
 * post em `publishing` com container e sem mídia cuja próxima checagem já
 * venceu há mais de um minuto — a folga evita duplicar o job que só está
 * esperando a vez.
 */
class CheckInstagramContainers extends Command
{
    protected $signature = 'instagram:check-containers {--limite=20 : Máximo de checagens reenfileiradas por execução}';

    protected $description = 'Reenfileira a checagem de status dos containers do Instagram cuja próxima verificação venceu';

    public function handle(TenantContext $tenant): int
    {
        $limite = max(1, (int) $this->option('limite'));

        $total = $tenant->withoutRestriction(function () use ($limite): int {
            $posts = Post::query()
                ->awaitingContainer()
                ->where(function ($q): void {
                    $q->whereNull('container_next_check_at')
                        ->orWhere('container_next_check_at', '<=', now()->subMinute());
                })
                ->orderBy('container_next_check_at')
                ->limit($limite)
                ->get();

            foreach ($posts as $post) {
                $post->forceFill(['container_next_check_at' => now()->addSeconds(CheckContainerStatusJob::RECHECK_SECONDS)])->save();

                CheckContainerStatusJob::dispatch($post->getKey(), $post->current_version);

                $this->line(sprintf('#%d: checagem do container %s reenfileirada.', $post->getKey(), $post->external_container_id));
            }

            return $posts->count();
        });

        $this->info(sprintf('%d checagem(ns) reenfileirada(s).', $total));

        return self::SUCCESS;
    }
}
