<?php

declare(strict_types=1);

namespace App\Jobs\Publishing;

use App\Jobs\Publishing\Concerns\LoadsPublishingPost;
use App\Models\Post;
use App\Notifications\PostPublished;
use App\Services\Integrations\SocialPublisherInterface;
use App\Services\Publishing\MediaContainerBuilder;
use App\Services\Publishing\PublishFailureHandler;
use App\Services\Publishing\PublishingQuotaGuard;
use App\Services\Publishing\PublishingRecipients;
use App\Services\Publishing\PublishLogger;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PublishStage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Etapa 3 (Seção 7.1.4): media_publish, permalink, primeiro comentário,
 * limpeza da ponte e do lock, aviso a quem interessa.
 *
 * external_post_id é gravado logo depois do media_publish, antes de qualquer
 * outra chamada: se o processo morrer no meio, a reexecução retoma dali sem
 * publicar duas vezes. Permalink e comentário que falham não desfazem uma
 * publicação que já aconteceu — viram aviso, não falha.
 */
class PublishContainerJob implements ShouldQueue
{
    use LoadsPublishingPost;
    use Queueable;

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

            if ($post === null || $post->status !== PostStatus::Publishing || $post->external_container_id === null) {
                return;
            }

            $post->touchPublishLock();

            $conta = $post->socialAccount;
            $mediaId = $post->external_post_id;

            if ($mediaId === null) {
                $request = ['creation_id' => $post->external_container_id, 'access_token' => $this->token($conta)];

                try {
                    $mediaId = $publisher->publish($conta, (string) $post->external_container_id);
                } catch (Throwable $e) {
                    $failures->handle($post, $e, PublishStage::Publish, $request);

                    return;
                }

                $post->forceFill(['external_post_id' => $mediaId])->save();
                $logger->success($post, PublishStage::Publish, $request, ['id' => $mediaId]);
            }

            $permalink = $this->fetchPermalink($post, $publisher, $logger, $mediaId);

            $post->transitionTo(PostStatus::Published);
            $post->forceFill([
                'external_permalink' => $permalink,
                'published_at' => now(),
                'last_error' => null,
                'last_error_is_permanent' => false,
                'next_attempt_at' => null,
                'container_next_check_at' => null,
            ])->save();

            $this->postFirstComment($post, $publisher, $logger, $mediaId);

            $builder->releaseAll($post);
            $quota->consume($conta);
            $post->releasePublishLock();

            Log::info('Publicação concluída', [
                'post_id' => $post->getKey(),
                'client_id' => $post->client_id,
                'media_id' => $mediaId,
                'permalink' => $permalink,
            ]);

            $destinatarios = $recipients->managersAndAuthor($post);

            if ($destinatarios->isNotEmpty()) {
                Notification::send($destinatarios, new PostPublished($post));
            }
        });
    }

    private function fetchPermalink(Post $post, SocialPublisherInterface $publisher, PublishLogger $logger, string $mediaId): ?string
    {
        $conta = $post->socialAccount;
        $request = ['media_id' => $mediaId, 'fields' => 'permalink', 'access_token' => $this->token($conta)];

        try {
            $link = $publisher->permalink($conta, $mediaId);
        } catch (Throwable $e) {
            // O post já está no ar; o link aparece como "indisponível" na UI.
            $logger->failure($post, PublishStage::Publish, $request, $e);
            Log::warning('Permalink indisponível após publicar', ['post_id' => $post->getKey(), 'erro' => $e->getMessage()]);

            return null;
        }

        $logger->success($post, PublishStage::Publish, $request, ['permalink' => $link]);

        return $link;
    }

    private function postFirstComment(Post $post, SocialPublisherInterface $publisher, PublishLogger $logger, string $mediaId): void
    {
        $mensagem = trim((string) $post->first_comment);
        $meta = $post->publish_meta ?? [];

        if ($mensagem === '' || ! $post->type->acceptsCaption() || isset($meta['comment_id'])) {
            return;
        }

        $conta = $post->socialAccount;
        $request = ['media_id' => $mediaId, 'message' => $mensagem, 'access_token' => $this->token($conta)];

        try {
            $commentId = $publisher->createComment($conta, $mediaId, $mensagem);
        } catch (Throwable $e) {
            $logger->failure($post, PublishStage::Comment, $request, $e);
            Log::warning('Primeiro comentário não publicado', ['post_id' => $post->getKey(), 'erro' => $e->getMessage()]);

            return;
        }

        $logger->success($post, PublishStage::Comment, $request, ['id' => $commentId]);
        $post->forceFill(['publish_meta' => ['comment_id' => $commentId] + $meta])->save();
    }
}
