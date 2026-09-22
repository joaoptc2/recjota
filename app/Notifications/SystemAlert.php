<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerta operacional para o owner (Seção 11.2): cron parado, jobs falhados,
 * tokens vencendo. Cada tipo é deduplicado por 12h no SystemHealthcheck.
 */
class SystemAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public const TIPO_CRON_PARADO = 'cron_parado';

    public const TIPO_JOBS_FALHADOS = 'jobs_falhados';

    public const TIPO_TOKENS_VENCENDO = 'tokens_vencendo';

    public function __construct(
        public readonly string $tipo,
        public readonly string $titulo,
        public readonly string $mensagem,
        public readonly string $acao,
        public readonly string $url,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->error()
            ->subject(sprintf('[%s] %s', config('agency.name'), $this->titulo))
            ->markdown('mail.system-alert', [
                'titulo' => $this->titulo,
                'mensagem' => $this->mensagem,
                'acao' => $this->acao,
                'url' => $this->url,
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'alerta_sistema',
            'alerta' => $this->tipo,
            'titulo' => $this->titulo,
            'mensagem' => $this->mensagem,
            'url' => $this->url,
        ];
    }
}
