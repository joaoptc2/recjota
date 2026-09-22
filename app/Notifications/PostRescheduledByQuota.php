<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * A conta bateu o teto de 50 publicações em 24h (Seção 7.1.5) e o post foi
 * empurrado para a próxima janela. O gestor decide se aceita o novo horário
 * ou reagenda manualmente.
 */
class PostRescheduledByQuota extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Post $post,
        public readonly Carbon $previousScheduledAt,
        public readonly int $quotaTotal,
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
            ->subject(sprintf('Post adiado por limite do Instagram — %s', $client->name))
            ->markdown('mail.post-rescheduled-by-quota', [
                'post' => $this->post,
                'client' => $client,
                'conta' => $this->post->socialAccount?->handle() ?? 'conta',
                'anterior' => display_datetime($this->previousScheduledAt, $client),
                'novo' => $this->post->scheduled_at !== null ? display_datetime($this->post->scheduled_at, $client) : 'indisponível',
                'limite' => $this->quotaTotal,
                'url' => route('painel.posts.show', $this->post),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'post_adiado_por_cota',
            'post_id' => $this->post->getKey(),
            'post_ulid' => $this->post->ulid,
            'cliente' => $this->post->client->name,
            'conta' => $this->post->socialAccount?->handle(),
            'agendado_antes' => $this->previousScheduledAt->toIso8601String(),
            'agendado_agora' => $this->post->scheduled_at?->toIso8601String(),
            'limite' => $this->quotaTotal,
        ];
    }
}
