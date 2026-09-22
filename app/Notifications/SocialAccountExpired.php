<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Conta social perdeu o acesso (Seção 7.1.3 / 6.12). Um gestor precisa
 * reconectar antes que os próximos posts agendados falhem.
 */
class SocialAccountExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SocialAccount $account,
        public readonly string $reason,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->account->client;

        return (new MailMessage)
            ->subject(sprintf('Reconecte o Instagram de %s', $client->name))
            ->markdown('mail.social-account-expired', [
                'account' => $this->account,
                'client' => $client,
                'reason' => $this->reason,
                'url' => route('painel.clients.show', $client),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'conta_social_expirada',
            'social_account_id' => $this->account->getKey(),
            'conta' => $this->account->handle(),
            'plataforma' => $this->account->platform->value,
            'cliente' => $this->account->client->name,
            'client_ulid' => $this->account->client->ulid,
            'motivo' => $this->reason,
        ];
    }
}
