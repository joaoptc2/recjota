<?php

declare(strict_types=1);

namespace App\Jobs\Publishing;

use App\Jobs\Publishing\Concerns\LoadsPublishingPost;
use App\Models\Post;
use App\Services\Integrations\SocialPublisherInterface;
use App\Services\Publishing\PublishFailureHandler;
use App\Services\Publishing\PublishLogger;
use App\Support\Enums\ContainerStatusCode;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PublishStage;
use App\Support\Exceptions\PublishingFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Etapa 2 (Seção 7.1.4): o Instagram processa a mídia de forma assíncrona.
 * Consulta status_code; IN_PROGRESS reagenda a si mesmo a cada 60s até 5
 * minutos desde a criação do container; FINISHED dispara a publicação;
 * ERROR/EXPIRED é falha permanente (a mídia foi recusada).
 */
class CheckContainerStatusJob implements ShouldQueue
{
    use LoadsPublishingPost;
    use Queueable;

    public const RECHECK_SECONDS = 60;

    public const TIMEOUT_MINUTES = 5;

    public function handle(
        TenantContext $tenant,
        SocialPublisherInterface $publisher,
        PublishLogger $logger,
        PublishFailureHandler $failures,
    ): void {
        $tenant->withoutRestriction(function () use ($publisher, $logger, $failures): void {
            $post = $this->loadPost();

            if ($post === null
                || $post->status !== PostStatus::Publishing
                || $post->external_container_id === null
                || $post->external_post_id !== null) {
                // Já publicou, falhou ou ainda não tem container: nada a checar.
                return;
            }

            $post->touchPublishLock();

            $conta = $post->socialAccount;
            $containerId = (string) $post->external_container_id;
            $request = ['container_id' => $containerId, 'fields' => 'status_code,status', 'access_token' => $this->token($conta)];

            $criadoEm = $post->container_created_at ?? now();
            $estourou = $criadoEm->lessThanOrEqualTo(now()->subMinutes(self::TIMEOUT_MINUTES));

            try {
                $status = $publisher->containerStatus($conta, $containerId);
            } catch (Throwable $e) {
                // Instabilidade na consulta não é falha da publicação: dentro
                // da janela de 5 min só reconsulta; a tentativa não é gasta.
                if (! $estourou && ! $failures->isPermanent($e)) {
                    $logger->failure($post, PublishStage::Status, $request, $e);
                    $this->recheckLater($post);

                    return;
                }

                $failures->handle($post, $e, PublishStage::Status, $request);

                return;
            }

            $logger->success($post, PublishStage::Status, $request, [
                'status_code' => $status->code->value,
                'status' => $status->message,
            ]);

            if ($status->isReady()) {
                // Sem próxima checagem: instagram:check-containers não deve
                // reenfileirar uma consulta enquanto o media_publish anda.
                $post->forceFill(['container_next_check_at' => null])->save();
                PublishContainerJob::dispatch($post->getKey(), $this->version);

                return;
            }

            if ($status->isFailed()) {
                $failures->handle($post, PublishingFailed::containerRejected($status->message), PublishStage::Status, $request);

                return;
            }

            if ($status->code === ContainerStatusCode::Published) {
                // Não fomos nós: publicar de novo duplicaria o post no perfil.
                $failures->handle($post, new PublishingFailed(
                    'O Instagram informa que esta mídia já foi publicada por outro caminho. Confira o perfil antes de reagendar.',
                    permanent: true,
                ), PublishStage::Status, $request);

                return;
            }

            if ($estourou) {
                $failures->handle($post, PublishingFailed::containerTimedOut(self::TIMEOUT_MINUTES), PublishStage::Status, $request);

                return;
            }

            $this->recheckLater($post);
        });
    }

    private function recheckLater(Post $post): void
    {
        $post->forceFill(['container_next_check_at' => now()->addSeconds(self::RECHECK_SECONDS)])->save();

        static::dispatch($post->getKey(), $this->version)->delay(now()->addSeconds(self::RECHECK_SECONDS));
    }
}
