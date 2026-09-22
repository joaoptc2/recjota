<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Models\CloudConnection;
use App\Support\Enums\ConnectionStatus;
use App\Support\SecretMask;
use Illuminate\Support\Facades\Log;

/**
 * Entrega um access token válido para uma conexão, renovando pelo refresh
 * token quando está vencido ou prestes a vencer. Renovação recusada (refresh
 * token revogado, consentimento retirado) marca a conexão como expirada com
 * a instrução de reconectar — o erro sobe para quem chamou decidir.
 */
class CloudTokenManager
{
    /** Renova quando faltam menos de 5 minutos: uma chamada demorada não pode pegar o token vencendo no meio. */
    public const REFRESH_MARGIN_SECONDS = 300;

    public function __construct(private readonly CloudStorageRegistry $registry) {}

    /** @throws CloudApiException */
    public function accessToken(CloudConnection $conexao): string
    {
        $token = (string) $conexao->access_token;

        if ($token !== '' && ! $conexao->isTokenExpiring(self::REFRESH_MARGIN_SECONDS)) {
            return $token;
        }

        return $this->refresh($conexao)->access_token;
    }

    /** @throws CloudApiException */
    public function refresh(CloudConnection $conexao): CloudConnection
    {
        $refreshToken = (string) $conexao->refresh_token;

        if ($refreshToken === '') {
            $conexao->fill([
                'status' => ConnectionStatus::Expired,
                'last_error' => sprintf('A conexão com o %s não tem token de renovação. Clique em "Reconectar" e autorize novamente.', $conexao->provider->label()),
            ])->save();

            throw new CloudApiException('Conexão sem refresh token', $conexao->provider, apiCode: 'invalid_grant');
        }

        try {
            $novo = $this->registry->for($conexao->provider)->refresh($refreshToken);
        } catch (CloudApiException $e) {
            if ($e->isAuthorizationLost()) {
                $conexao->fill([
                    'status' => ConnectionStatus::Expired,
                    'last_error' => $e->actionableMessage(),
                ])->save();

                Log::error('Nuvem: renovação recusada; conexão marcada como expirada', [
                    'provedor' => $conexao->provider->value,
                    'client_id' => $conexao->client_id,
                    'conta' => $conexao->account_email,
                    'erro' => $e->getMessage(),
                ]);
            }

            throw $e;
        }

        $conexao->fill([
            'access_token' => $novo->accessToken,
            'refresh_token' => $novo->refreshToken ?? $refreshToken,
            'token_expires_at' => $novo->expiresAt,
            'status' => ConnectionStatus::Connected,
            'last_error' => null,
        ]);
        $conexao->forceFill(['token_refreshed_at' => now()]);
        $conexao->save();

        Log::info('Nuvem: token renovado', [
            'provedor' => $conexao->provider->value,
            'client_id' => $conexao->client_id,
            'token' => SecretMask::mask($novo->accessToken),
            'expira_em' => $novo->expiresAt?->toIso8601String(),
        ]);

        return $conexao;
    }
}
