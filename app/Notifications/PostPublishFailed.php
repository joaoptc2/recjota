<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Post;
use App\Support\Enums\PublishStage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A publicação falhou de vez (Seção 8.3): erro permanente ou tentativas
 * esgotadas. A mensagem já diz o que fazer; o e-mail só a entrega.
 */
class PostPublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Post $post,
        public readonly string $reason,
        public readonly PublishStage $stage,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->post->client;

        return (new MailMessage)
            ->error()
            ->subject(sprintf('Post não publicado — %s', $client->name))
            ->markdown('mail.post-publish-failed', [
                'post' => $this->post,
                'client' => $client,
                'conta' => $this->post->socialAccount?->handle() ?? 'conta',
                'reason' => $this->reason,
                'etapa' => $this->stage->label(),
                'url' => route('painel.posts.show', $this->post),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'post_falhou',
            'post_id' => $this->post->getKey(),
            'post_ulid' => $this->post->ulid,
            'cliente' => $this->post->client->name,
            'conta' => $this->post->socialAccount?->handle(),
            'etapa' => $this->stage->value,
            'motivo' => $this->reason,
            'tentativas' => $this->post->publish_attempts,
        ];
    }
}
