<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Approval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Lembrete de prazo vencendo (Seção 6.11), disparado pelo cron horário. */
class ApprovalDeadlineApproaching extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
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
        $post = $this->approval->post;

        return (new MailMessage)
            ->subject(sprintf('Faltam poucas horas — %s', $post->client->name))
            ->markdown('mail.approval-deadline', [
                'post' => $post,
                'approval' => $this->approval,
                'client' => $post->client,
                'url' => $this->magicUrl,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'prazo_de_aprovacao',
            'post_id' => $this->approval->post_id,
            'prazo' => $this->approval->due_at?->toIso8601String(),
        ];
    }
}
