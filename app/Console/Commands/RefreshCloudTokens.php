<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CloudConnection;
use App\Notifications\CloudConnectionExpired;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudTokenManager;
use App\Services\Publishing\PublishingRecipients;
use App\Support\Enums\ConnectionStatus;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Renovação diária dos tokens de Google Drive e OneDrive (Seções 7.2/7.3).
 *
 * O access token dura 1h e é renovado sob demanda; este comando existe para
 * (1) manter o refresh token da Microsoft ativo — ele expira após 90 dias
 * sem uso — e (2) descobrir cedo que o consentimento foi retirado, avisando
 * o gestor antes de um post agendado falhar por isso.
 */
class RefreshCloudTokens extends Command
{
    protected $signature = 'cloud:refresh-tokens';

    protected $description = 'Renova os tokens das conexões de nuvem e avisa quando o acesso foi perdido';

    public function handle(TenantContext $tenant, CloudTokenManager $tokens, PublishingRecipients $recipients): int
    {
        [$renovadas, $expiradas, $adiadas] = $tenant->withoutRestriction(function () use ($tokens, $recipients): array {
            $renovadas = $expiradas = $adiadas = 0;

            CloudConnection::query()
                ->withoutClientScope()
                ->where('status', ConnectionStatus::Connected->value)
                ->orderBy('id')
                ->chunkById(50, function ($conexoes) use ($tokens, $recipients, &$renovadas, &$expiradas, &$adiadas): void {
                    foreach ($conexoes as $conexao) {
                        try {
                            $tokens->refresh($conexao);
                            $renovadas++;
                            $this->line(sprintf('%s (%s): renovado.', $conexao->label(), $conexao->provider->label()));
                        } catch (CloudApiException $e) {
                            if ($e->isAuthorizationLost()) {
                                $expiradas++;
                                $this->warn(sprintf('%s (%s): acesso perdido — %s', $conexao->label(), $conexao->provider->label(), $e->actionableMessage()));
                                $this->notify($conexao, $recipients);
                            } else {
                                $adiadas++;
                                $this->line(sprintf('%s (%s): adiado — %s', $conexao->label(), $conexao->provider->label(), $e->actionableMessage()));
                            }
                        }
                    }
                });

            return [$renovadas, $expiradas, $adiadas];
        });

        $this->info(sprintf('%d renovada(s), %d expirada(s), %d adiada(s).', $renovadas, $expiradas, $adiadas));

        return self::SUCCESS;
    }

    private function notify(CloudConnection $conexao, PublishingRecipients $recipients): void
    {
        $destinatarios = $recipients->managersForClient($conexao->client_id);

        if ($destinatarios->isNotEmpty()) {
            Notification::send($destinatarios, new CloudConnectionExpired($conexao));
        }
    }
}
