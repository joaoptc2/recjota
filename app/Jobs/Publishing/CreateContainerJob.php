<?php

declare(strict_types=1);

namespace App\Jobs\Publishing;

use App\Jobs\Publishing\Concerns\LoadsPublishingPost;
use App\Models\Post;
use App\Notifications\PostRescheduledByQuota;
use App\Services\Integrations\SocialPublisherInterface;
use App\Services\Publishing\MediaContainerBuilder;
use App\Services\Publishing\PublishFailureHandler;
use App\Services\Publishing\PublishingQuotaGuard;
use App\Services\Publishing\PublishingRecipients;
use App\Services\Publishing\PublishLogger;
use App\Support\DataObjects\MediaContainerData;
use App\Support\DataObjects\PublishingLimit;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\PublishStage;
use App\Support\Exceptions\PublishingFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Etapa 1 do motor de publicação (Seção 8): lock, cota, ponte de mídia e
 * container(s). Termina agendando a checagem de status para +60s.
 *
 * Idempotente pela chave post_id + versão (Post::idempotencyKey): reexecutar
 * com o container já criado não fala com a API. Curto de propósito: cada job
 * faz uma ou poucas chamadas e devolve o controle à fila (R5).
 */
class CreateContainerJob implements ShouldQueue
{
    use LoadsPublishingPost;
    use Queueable;

    public const CHECK_DELAY_SECONDS = 60;

    /**
     * Filhos de carrossel criados por execução. Cada chamada pode levar até
     * 8s; acima disto o job devolve o controle à fila e continua na próxima
     * (os filhos já criados ficam em publish_meta), para caber na janela de
     * 45s do cron (R5).
     */
    public const CAROUSEL_CHILDREN_PER_RUN = 4;

    public function handle(
        TenantContext $tenant,
        SocialPublisherInterface $publisher,
        MediaContainerBuilder $builder,
        PublishingQuotaGuard $quota,
        PublishLogger $logger,
        PublishFailureHandler $failures,
        PublishingRecipients $recipients,
    ): void {
        $tenant->withoutRestriction(function () use ($publisher, $builder, $quota, $logger, $failures, $recipients): void {
            $post = $this->loadPost();

            if ($post === null || ! $this->shouldRun($post)) {
                return;
            }

            // Lock pessimista: dois crons sobrepostos disputam aqui e só um segue.
            if (! $post->acquirePublishLock()) {
                Log::info('Publicação: post já travado por outro processo', ['post_id' => $post->getKey()]);

                return;
            }

            $conta = $post->socialAccount;

            if ($conta === null || $conta->client_id !== $post->client_id || $conta->needsReconnection()) {
                $this->enterPublishing($post);
                $failures->handle($post, new PublishingFailed(match (true) {
                    $conta === null => 'O post não tem conta do Instagram vinculada. Escolha a conta e reagende.',
                    $conta->client_id !== $post->client_id => 'A conta do Instagram vinculada pertence a outro cliente. Escolha uma conta deste cliente e reagende.',
                    default => sprintf('A conta %s está desconectada. Reconecte a conta em Integrações e reagende o post.', $conta->handle()),
                }, permanent: true), PublishStage::Container);

                return;
            }

            // Retry depois de falha transitória no media_publish: o container
            // continua válido no Instagram. Não gasta cota nem cria outro —
            // só volta a consultar o status (que barra PUBLISHED).
            if ($post->hasUsableContainer()) {
                $this->enterPublishing($post);
                $this->scheduleStatusCheck($post, 0);

                return;
            }

            // Cota ANTES de mudar de estado: publishing só sai para published/failed.
            try {
                $limite = $quota->check($conta);
            } catch (Throwable $e) {
                $this->enterPublishing($post);
                $failures->handle($post, $e, PublishStage::Quota, ['fields' => 'quota_usage,config', 'access_token' => $this->token($conta)]);

                return;
            }

            if ($limite->isExhausted()) {
                $this->rescheduleForQuota($post, $limite, $quota, $logger, $recipients);

                return;
            }

            $logger->success($post, PublishStage::Quota, ['fields' => 'quota_usage,config', 'access_token' => $this->token($conta)], [
                'quota_usage' => $limite->quotaUsage,
                'quota_total' => $limite->quotaTotal,
            ]);

            $this->enterPublishing($post);

            $request = [];

            try {
                $containerId = $post->type === PostType::Carousel
                    ? $this->createCarousel($post, $publisher, $builder, $logger, $request)
                    : $this->createSingle($post, $publisher, $builder, $logger, $request);
            } catch (Throwable $e) {
                $failures->handle($post, $e, PublishStage::Container, $request);

                return;
            }

            // Carrossel grande: parte dos filhos ficou para a próxima execução.
            if ($containerId === null) {
                $post->releasePublishLock();
                static::dispatch($post->getKey(), $this->version);

                return;
            }

            $post->forceFill([
                'external_container_id' => $containerId,
                'container_created_at' => now(),
            ])->save();

            $this->scheduleStatusCheck($post, self::CHECK_DELAY_SECONDS);
        });
    }

    private function scheduleStatusCheck(Post $post, int $delaySeconds): void
    {
        $post->forceFill(['container_next_check_at' => now()->addSeconds($delaySeconds)])->save();

        $job = CheckContainerStatusJob::dispatch($post->getKey(), $this->version);

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }
    }

    /**
     * Entra em publishing (única saída: published ou failed). Uma rodada nova
     * (vinda de approved/scheduled) zera o contador de tentativas e o último
     * erro: são de uma publicação anterior que o usuário já corrigiu. Um post
     * retomado de um job morto ou em retry mantém o contador.
     */
    private function enterPublishing(Post $post): void
    {
        $campos = ['next_attempt_at' => null, 'container_next_check_at' => null];

        if ($post->status->isPublishable()) {
            $campos += ['publish_attempts' => 0, 'last_error' => null, 'last_error_is_permanent' => false];
        }

        if ($post->status !== PostStatus::Publishing) {
            $post->transitionTo(PostStatus::Publishing);
        }

        $post->forceFill($campos)->save();
    }

    /**
     * Reavalia a elegibilidade no momento da execução: o job pode ter ficado
     * na fila e o mundo mudou (edição, reagendamento, outro cron).
     */
    private function shouldRun(Post $post): bool
    {
        if ($post->status === PostStatus::Published) {
            return false;
        }

        // Idempotência: já há container (ou mídia) para esta versão.
        if ($post->status === PostStatus::Publishing) {
            if ($post->external_container_id !== null || $post->external_post_id !== null) {
                return false;
            }

            // Publishing sem container: job anterior morreu antes de criar.
            // Só um lock vencido permite retomar; acquirePublishLock decide.
            return true;
        }

        if ($post->approval_status !== ApprovalStatus::NotRequired && ! $post->isApprovedVersionCurrent()) {
            Log::info('Publicação: versão atual difere da aprovada; post não publicado', ['post_id' => $post->getKey()]);

            return false;
        }

        if ($post->status->isPublishable()) {
            return $post->scheduled_at !== null && $post->scheduled_at->lessThanOrEqualTo(now());
        }

        if ($post->status === PostStatus::Failed) {
            return ! $post->last_error_is_permanent
                && $post->hasPublishAttemptsLeft()
                && $post->next_attempt_at !== null
                && $post->next_attempt_at->lessThanOrEqualTo(now())
                && ($post->scheduled_at === null || $post->scheduled_at->lessThanOrEqualTo(now()));
        }

        return false;
    }

    /** @param  array<string, mixed>  $request  Preenchido por referência para o log de falha */
    private function createSingle(Post $post, SocialPublisherInterface $publisher, MediaContainerBuilder $builder, PublishLogger $logger, array &$request): string
    {
        $dados = $builder->single($post);

        return $this->createContainer($post, $dados, $publisher, $logger, $request);
    }

    /**
     * Carrossel: um container por item (is_carousel_item) e depois o pai com
     * children. Filhos já criados ficam em publish_meta para que uma
     * reexecução não os duplique. Devolve null quando ainda faltam filhos e
     * a cota de chamadas desta execução acabou (o job se reenfileira).
     *
     * @param  array<string, mixed>  $request
     */
    private function createCarousel(Post $post, SocialPublisherInterface $publisher, MediaContainerBuilder $builder, PublishLogger $logger, array &$request): ?string
    {
        $itens = $post->postMedia->values();
        $minimo = PostType::Carousel->minMediaItems();
        $maximo = PostType::Carousel->maxMediaItems();

        if ($itens->count() < $minimo || $itens->count() > $maximo) {
            throw new PublishingFailed(sprintf(
                'Um carrossel precisa ter de %d a %d itens; este tem %d. Ajuste as mídias e reagende.',
                $minimo,
                $maximo,
                $itens->count(),
            ), permanent: true);
        }

        $meta = $post->publish_meta ?? [];
        $filhos = is_array($meta['children'] ?? null) ? $meta['children'] : [];
        $criadosAgora = 0;

        foreach ($itens as $indice => $item) {
            if (isset($filhos[$indice])) {
                continue;
            }

            if ($criadosAgora >= self::CAROUSEL_CHILDREN_PER_RUN) {
                Log::info('Publicação: carrossel continua na próxima execução', [
                    'post_id' => $post->getKey(),
                    'filhos_criados' => count($filhos),
                    'total' => $itens->count(),
                ]);

                return null;
            }

            $filhos[$indice] = $this->createContainer($post, $builder->carouselItem($item), $publisher, $logger, $request);
            $criadosAgora++;

            $post->forceFill(['publish_meta' => ['children' => $filhos] + $meta])->save();
        }

        ksort($filhos);

        return $this->createContainer($post, $builder->carousel($post, array_values($filhos)), $publisher, $logger, $request);
    }

    /** @param  array<string, mixed>  $request */
    private function createContainer(Post $post, MediaContainerData $dados, SocialPublisherInterface $publisher, PublishLogger $logger, array &$request): string
    {
        $conta = $post->socialAccount;
        $request = $dados->toParams() + ['access_token' => $this->token($conta)];

        $id = $publisher->createContainer($conta, $dados);

        $logger->success($post, PublishStage::Container, $request, ['id' => $id]);

        return $id;
    }

    /**
     * Cota esgotada (Seção 7.1.5): não tenta. O post volta para a fila no
     * início da próxima janela e o gestor fica sabendo.
     */
    private function rescheduleForQuota(Post $post, PublishingLimit $limite, PublishingQuotaGuard $quota, PublishLogger $logger, PublishingRecipients $recipients): void
    {
        $conta = $post->socialAccount;
        $anterior = $post->scheduled_at?->copy() ?? now();
        $novo = $quota->nextFreeWindowStart($conta, $limite);

        $mensagem = sprintf(
            'A conta %s atingiu o limite de %d publicações em 24h do Instagram. O post foi reagendado automaticamente para %s.',
            $conta->handle(),
            $limite->quotaTotal,
            display_datetime($novo, $post->client),
        );

        $logger->failure($post, PublishStage::Quota, ['fields' => 'quota_usage,config', 'access_token' => $this->token($conta)], $mensagem, [
            'quota_usage' => $limite->quotaUsage,
            'quota_total' => $limite->quotaTotal,
        ]);

        // Post retomado de um job morto já está em publishing, de onde não se
        // volta para scheduled: vira failed transitório com a próxima tentativa
        // na janela livre — sem contar tentativa, porque nada foi tentado.
        if ($post->status === PostStatus::Publishing) {
            $post->transitionTo(PostStatus::Failed);
        } elseif ($post->status !== PostStatus::Scheduled) {
            $post->transitionTo(PostStatus::Scheduled);
        }

        $post->forceFill([
            'scheduled_at' => $novo,
            'last_error' => $mensagem,
            'last_error_is_permanent' => false,
            'next_attempt_at' => $post->status === PostStatus::Failed ? $novo : null,
            'locked_at' => null,
            'locked_by' => null,
        ])->save();

        Log::warning('Publicação adiada por cota', [
            'post_id' => $post->getKey(),
            'conta' => $conta->handle(),
            'novo_horario' => $novo->toIso8601String(),
        ]);

        $destinatarios = $recipients->managers($post);

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new PostRescheduledByQuota($post, $anterior, $limite->quotaTotal));
        }
    }
}
