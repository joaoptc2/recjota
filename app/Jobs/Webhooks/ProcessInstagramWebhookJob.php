<?php

declare(strict_types=1);

namespace App\Jobs\Webhooks;

use App\Models\SocialAccount;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Processa um evento do webhook do Instagram (Seção 7.1.6) fora da
 * requisição HTTP.
 *
 * Por enquanto só registra o que chegou (log + activity log), associando
 * ao SocialAccount pelo id da conta quando ele existe.
 *
 * TODO (caixa de entrada): quando a inbox de comentários/menções existir,
 * eventos `comments` viram registros para triagem e `messages`/`mentions`
 * ganham tratamento próprio. Até lá nada além do registro é feito, de
 * propósito — nenhum dado do cliente nasce de um evento sem tela para vê-lo.
 */
class ProcessInstagramWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array<string, mixed>  $payload */
    public function __construct(public readonly array $payload) {}

    public function handle(TenantContext $tenant): void
    {
        $tenant->withoutRestriction(function (): void {
            $entradas = is_array($this->payload['entry'] ?? null) ? $this->payload['entry'] : [];

            foreach ($entradas as $entrada) {
                $externalId = (string) ($entrada['id'] ?? '');

                $conta = $externalId !== ''
                    ? SocialAccount::query()->where('external_id', $externalId)->first()
                    : null;

                $mudancas = is_array($entrada['changes'] ?? null) ? $entrada['changes'] : [];

                if ($mudancas === []) {
                    $mudancas = [['field' => 'desconhecido', 'value' => $entrada]];
                }

                foreach ($mudancas as $mudanca) {
                    $campo = (string) ($mudanca['field'] ?? 'desconhecido');

                    Log::info('Webhook do Instagram recebido', [
                        'campo' => $campo,
                        'external_id' => $externalId,
                        'social_account_id' => $conta?->getKey(),
                        'valor' => $mudanca['value'] ?? null,
                    ]);

                    $registro = activity('webhook')
                        ->event('instagram.'.$campo)
                        ->withProperties([
                            'external_id' => $externalId,
                            'valor' => $mudanca['value'] ?? null,
                        ]);

                    if ($conta !== null) {
                        $registro->performedOn($conta);
                    }

                    $registro->log(sprintf('Evento %s do Instagram recebido', $campo));
                }
            }
        });
    }
}
