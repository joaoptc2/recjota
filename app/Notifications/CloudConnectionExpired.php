<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\Scopes\ClientScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A conexão com Google Drive / OneDrive perdeu o acesso (Seções 7.2/7.3).
 * Posts com mídia dessa conta vão falhar na publicação até alguém reconectar.
 */
class CloudConnectionExpired extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly CloudConnection $cloudConnection) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->client();

        return (new MailMessage)
            ->subject(sprintf('Reconecte o %s de %s', $this->cloudConnection->provider->label(), $client?->name ?? 'um cliente'))
            ->markdown('mail.cloud-connection-expired', [
                'connection' => $this->cloudConnection,
                'client' => $client,
                'reason' => $this->cloudConnection->last_error ?? 'O provedor não reconhece mais o acesso desta conexão.',
                'url' => $client !== null ? route('painel.clients.show', $client) : route('painel.dashboard'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $client = $this->client();

        return [
            'tipo' => 'conexao_nuvem_expirada',
            'cloud_connection_id' => $this->cloudConnection->getKey(),
            'provedor' => $this->cloudConnection->provider->value,
            'conta' => $this->cloudConnection->label(),
            'cliente' => $client?->name,
            'client_ulid' => $client?->ulid,
            'motivo' => $this->cloudConnection->last_error,
        ];
    }

    private function client(): ?Client
    {
        return Client::query()->withoutGlobalScope(ClientScope::class)->find($this->cloudConnection->client_id);
    }
}
