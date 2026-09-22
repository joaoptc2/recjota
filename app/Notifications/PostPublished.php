<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** O post saiu no Instagram (Seção 8). Vai para o autor e para os gestores do cliente. */
class PostPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Post $post) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->post->client;

        return (new MailMessage)
            ->subject(sprintf('Post publicado — %s', $client->name))
            ->markdown('mail.post-published', [
                'post' => $this->post,
                'client' => $client,
                'conta' => $this->post->socialAccount?->handle() ?? 'conta',
                'permalink' => $this->post->external_permalink,
                'url' => route('painel.posts.show', $this->post),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'post_publicado',
            'post_id' => $this->post->getKey(),
            'post_ulid' => $this->post->ulid,
            'cliente' => $this->post->client->name,
            'conta' => $this->post->socialAccount?->handle(),
            'permalink' => $this->post->external_permalink,
            'publicado_em' => $this->post->published_at?->toIso8601String(),
        ];
    }
}
