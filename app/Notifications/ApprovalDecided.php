<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Approval;
use App\Models\Post;
use App\Support\Enums\ApprovalStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Avisa a equipe da decisão do cliente (Seção 6.11). */
class ApprovalDecided extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Post $post,
        public readonly Approval $approval,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verbo = match ($this->approval->status) {
            ApprovalStatus::Approved => 'aprovou',
            ApprovalStatus::Rejected => 'reprovou',
            default => 'pediu ajustes em',
        };

        return (new MailMessage)
            ->subject(sprintf('%s %s um post — %s',
                $this->approval->decided_by_name ?? 'O cliente',
                $verbo,
                $this->post->client->name,
            ))
            ->markdown('mail.approval-decided', [
                'post' => $this->post,
                'approval' => $this->approval,
                'client' => $this->post->client,
                'verbo' => $verbo,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'aprovacao_decidida',
            'post_id' => $this->post->getKey(),
            'post_ulid' => $this->post->ulid,
            'decisao' => $this->approval->status->value,
            'nota' => $this->approval->decision_note,
            'cliente' => $this->post->client->name,
        ];
    }
}
