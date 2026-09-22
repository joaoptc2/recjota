<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Publishing\CreateContainerJob;
use App\Models\Post;
use App\Support\Enums\ApprovalStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Despachante do motor de publicação (Seção 6.7 / 8). Roda a cada minuto e
 * enfileira CreateContainerJob para o que está na hora:
 *
 *  - approved/scheduled com scheduled_at <= agora;
 *  - failed transitório com next_attempt_at <= agora e tentativas sobrando;
 *  - nunca com lock ativo, nunca com versão aprovada diferente da atual.
 *
 * No máximo 20 por passada, para caber na janela de 45s da fila (R5). O
 * lock em si é adquirido pelo job, não aqui: o despachante só seleciona.
 */
class DispatchDuePosts extends Command
{
    protected $signature = 'posts:dispatch-due {--limite=20 : Máximo de posts despachados por execução}';

    protected $description = 'Enfileira a publicação dos posts cuja hora chegou (ou cuja nova tentativa venceu)';

    public function handle(TenantContext $tenant): int
    {
        $limite = max(1, (int) $this->option('limite'));

        [$despachados, $pulados] = $tenant->withoutRestriction(function () use ($limite): array {
            $posts = Post::query()
                ->eligibleForPublishing()
                ->unlocked()
                ->with('socialAccount')
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->limit($limite)
                ->get();

            $despachados = 0;
            $pulados = 0;

            foreach ($posts as $post) {
                // Aprovação vale para uma versão; editou depois, não sai (Seção 6.6).
                if ($post->approval_status !== ApprovalStatus::NotRequired && ! $post->isApprovedVersionCurrent()) {
                    $this->line(sprintf('#%d pulado: versão atual (%d) difere da aprovada (%s).', $post->getKey(), $post->current_version, $post->approved_version ?? 'nenhuma'));
                    $pulados++;

                    continue;
                }

                CreateContainerJob::dispatch($post->getKey(), $post->current_version);
                $despachados++;

                $this->line(sprintf('#%d despachado (%s, v%d).', $post->getKey(), $post->socialAccount?->handle() ?? 'sem conta', $post->current_version));
            }

            return [$despachados, $pulados];
        });

        $this->info(sprintf('%d despachado(s), %d pulado(s).', $despachados, $pulados));

        return self::SUCCESS;
    }
}
