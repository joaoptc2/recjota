<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Storage;

/** O relatório do mês está pronto (Seção 6.8): PDF anexo e link para o portal/painel. */
class MonthlyReportReady extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Report $report) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $client = $this->report->client;
        $portal = method_exists($notifiable, 'isClient') && $notifiable->isClient();

        $mensagem = (new MailMessage)
            ->subject(sprintf('Relatório de %s — %s', $this->report->periodLabel(), $client->name))
            ->markdown('mail.monthly-report-ready', [
                'report' => $this->report,
                'client' => $client,
                'url' => $portal ? route('portal.reports') : route('painel.reports', ['cliente' => $client->ulid]),
            ]);

        if (Storage::disk('local')->exists($this->report->path)) {
            $mensagem->attachData((string) Storage::disk('local')->get($this->report->path), $this->report->filename(), ['mime' => 'application/pdf']);
        }

        return $mensagem;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'tipo' => 'relatorio_mensal',
            'report_ulid' => $this->report->ulid,
            'cliente' => $this->report->client->name,
            'client_ulid' => $this->report->client->ulid,
            'periodo' => $this->report->periodLabel(),
        ];
    }
}
