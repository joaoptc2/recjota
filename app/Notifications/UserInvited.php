<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Link de aceite do convite (Seção 6.1). O token em claro só existe aqui;
 * o banco guarda o hash.
 */
class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Invitation $invitation,
        private readonly string $plainToken,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function acceptUrl(): string
    {
        return route('invitation.show', $this->plainToken);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $agencia = config('agency.name');

        return (new MailMessage)
            ->subject(sprintf('Convite para o %s', $agencia))
            ->markdown('mail.user-invited', [
                'invitation' => $this->invitation,
                'agencia' => $agencia,
                'url' => $this->acceptUrl(),
                'convidadoPor' => $this->invitation->inviter?->name,
            ]);
    }
}
