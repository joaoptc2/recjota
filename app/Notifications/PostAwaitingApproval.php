<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Approval;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * O e-mail que carrega o link mágico (Seção 6.6).
 *
 * Cada destinatário recebe um link próprio: assim a decisão fica atribuída a
 * uma pessoa, e revogar o acesso de alguém não derruba o dos outros.
 */
class PostAwaitingApproval extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Post $post,
        public readonly Approval $approval,
        public readonly string $magicUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $cliente = $this->post->client;

        return (new MailMessage)
            ->subject(sprintf('Aprovação pendente — %s', $cliente->name))
            ->markdown('mail.post-awaiting-approval', [
                'post' => $this->post,
                'approval' => $this->approval,
                'client' => $cliente,
                'url' => $this->magicUrl,
                'saudacao' => $notifiable->name ?? null,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'aprovacao_pendente',
            'post_id' => $this->post->getKey(),
            'post_ulid' => $this->post->ulid,
            'cliente' => $this->post->client->name,
            'prazo' => $this->approval->due_at?->toIso8601String(),
        ];
    }
}
