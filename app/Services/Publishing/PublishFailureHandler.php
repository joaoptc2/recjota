<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Post;
use App\Notifications\PostPublishFailed;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PublishStage;
use App\Support\Exceptions\MediaBridgeFailed;
use App\Support\Exceptions\PublishingFailed;
use App\Support\Publishing\PublishBackoff;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Único lugar que decide o destino de um post cuja publicação falhou
 * (Seção 8.3):
 *
 *  - transitório (rede, 5xx, rate limit, timeout de processamento): conta a
 *    tentativa, agenda next_attempt_at pelo backoff e deixa o post `failed`
 *    para o despachante pegar de novo; após 5 tentativas vira definitivo;
 *  - permanente (token revogado, permissão negada, mídia recusada): `failed`
 *    na hora, sem retry, com instrução do que fazer e aviso ao gestor.
 *
 * O lock é sempre liberado. Container e ponte de mídia são descartados,
 * exceto quando a falha foi transitória na etapa de publicação (media_publish):
 * o container já está pronto no Instagram, e recriá-lo custaria chamadas e
 * poderia duplicar a mídia; a próxima tentativa só reconsulta o status.
 */
class PublishFailureHandler
{
    public function __construct(
        private readonly PublishLogger $logger,
        private readonly MediaContainerBuilder $containers,
        private readonly PublishingRecipients $recipients,
    ) {}

    /** @param  array<string, mixed>  $request  Payload da chamada que falhou (será mascarado) */
    public function handle(Post $post, Throwable $erro, PublishStage $stage, array $request = []): void
    {
        $permanente = $this->isPermanent($erro);
        $mensagem = $this->messageFor($post, $erro);

        $this->logger->failure($post, $stage, $request, $erro);

        $tentativas = (int) $post->publish_attempts + 1;
        // MAX é o número de novas tentativas: a de número MAX+1 é a última,
        // e só depois dela (com o degrau de 4h usado) o sistema desiste.
        $esgotou = ! $permanente && $tentativas > Post::MAX_PUBLISH_ATTEMPTS;
        $definitivo = $permanente || $esgotou;

        if ($esgotou) {
            $mensagem = sprintf(
                'Falhou %d vezes seguidas; o sistema parou de tentar. Último erro: %s Corrija e reagende o post.',
                $tentativas,
                $mensagem,
            );
        }

        if ($post->status !== PostStatus::Failed) {
            $post->transitionTo(PostStatus::Failed);
        }

        $manterContainer = ! $definitivo
            && $stage === PublishStage::Publish
            && $post->hasUsableContainer();

        $post->forceFill([
            'publish_attempts' => $tentativas,
            'last_error' => $mensagem,
            'last_error_is_permanent' => $definitivo,
            'next_attempt_at' => $definitivo ? null : PublishBackoff::nextAttemptAt($tentativas),
            'container_next_check_at' => null,
            'locked_at' => null,
            'locked_by' => null,
        ]);

        if (! $manterContainer) {
            $post->forceFill([
                'external_container_id' => null,
                'container_created_at' => null,
                'publish_meta' => null,
            ]);
        }

        $post->save();

        if (! $manterContainer) {
            $this->safelyReleaseBridge($post);
        }

        $this->markAccountIfTokenRevoked($post, $erro);

        Log::log($definitivo ? 'error' : 'warning', 'Publicação falhou', [
            'post_id' => $post->getKey(),
            'client_id' => $post->client_id,
            'etapa' => $stage->value,
            'tentativa' => $tentativas,
            'permanente' => $definitivo,
            'proxima' => $post->next_attempt_at?->toIso8601String(),
            'erro' => $erro->getMessage(),
        ]);

        if ($definitivo) {
            $destinatarios = $this->recipients->managersAndAuthor($post);

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, new PostPublishFailed($post, $mensagem, $stage));
            }
        }
    }

    public function isPermanent(Throwable $erro): bool
    {
        return match (true) {
            $erro instanceof InstagramApiException => $erro->isPermanent(),
            $erro instanceof PublishingFailed => $erro->isPermanent(),
            // Arquivo sumiu ou MIME não aceito: só um humano resolve.
            $erro instanceof MediaBridgeFailed => true,
            default => false,
        };
    }

    /** Mensagem em pt-BR que diz o que aconteceu e o que fazer. Sem token, sem stack. */
    public function messageFor(Post $post, Throwable $erro): string
    {
        $conta = $post->socialAccount?->handle() ?? 'a conta';

        if ($erro instanceof InstagramApiException) {
            if ($erro->isTokenInvalid()) {
                return sprintf('O acesso da conta %s foi revogado ou expirou. Reconecte a conta em Integrações e reagende o post.', $conta);
            }

            if ($erro->isPermissionDenied()) {
                return sprintf('A conta %s não concedeu as permissões necessárias. Reconecte a conta em Integrações aceitando todas as permissões.', $conta);
            }

            return $erro->actionableMessage();
        }

        if ($erro instanceof PublishingFailed || $erro instanceof MediaBridgeFailed) {
            return $erro->getMessage();
        }

        return 'Erro inesperado ao publicar. O sistema vai tentar de novo; se persistir, fale com o suporte.';
    }

    private function safelyReleaseBridge(Post $post): void
    {
        try {
            $this->containers->releaseAll($post->loadMissing('postMedia.mediaAsset'));
        } catch (Throwable $e) {
            Log::warning('Não foi possível liberar a ponte de mídia', ['post_id' => $post->getKey(), 'erro' => $e->getMessage()]);
        }
    }

    /** Token revogado afeta a conta inteira: marcar evita que os próximos posts falhem em silêncio. */
    private function markAccountIfTokenRevoked(Post $post, Throwable $erro): void
    {
        if (! $erro instanceof InstagramApiException || ! $erro->isTokenInvalid()) {
            return;
        }

        $conta = $post->socialAccount;

        if ($conta === null || $conta->connection_status === ConnectionStatus::Expired) {
            return;
        }

        $conta->fill([
            'connection_status' => ConnectionStatus::Expired,
            'last_error' => $erro->actionableMessage(),
        ])->save();
    }
}
