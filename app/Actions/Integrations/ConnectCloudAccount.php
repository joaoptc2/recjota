<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\User;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudStorageRegistry;
use App\Support\DataObjects\CloudProfile;
use App\Support\DataObjects\CloudTokens;
use App\Support\Enums\CloudProvider;
use App\Support\Enums\ConnectionStatus;
use App\Support\SecretMask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conecta (ou reconecta) Google Drive / OneDrive a um cliente.
 *
 * A mesma conta do provedor pode servir a mais de um cliente (a agência
 * pode usar o próprio Drive para vários), então a unicidade é por
 * cliente + provedor + conta: reconectar atualiza o registro e mantém os
 * assets já importados apontando para ele.
 */
class ConnectCloudAccount
{
    /** Orçamento das duas chamadas do callback (R5: requisição < 10s). */
    public const CALLBACK_BUDGET_SECONDS = 8;

    public function __construct(private readonly CloudStorageRegistry $registry) {}

    /** @throws CloudApiException */
    public function fromAuthorizationCode(Client $client, CloudProvider $provider, string $code, ?User $consentedBy): CloudConnection
    {
        $cliente = $this->registry->for($provider);
        $tokens = $cliente->exchangeCode($code);
        $perfil = $cliente->profile($tokens->accessToken);

        return $this->persist($client, $provider, $tokens, $perfil, $consentedBy);
    }

    public function persist(Client $client, CloudProvider $provider, CloudTokens $tokens, CloudProfile $perfil, ?User $consentedBy): CloudConnection
    {
        return DB::transaction(function () use ($client, $provider, $tokens, $perfil, $consentedBy): CloudConnection {
            $conexao = CloudConnection::query()
                ->withoutClientScope()
                ->where('client_id', $client->getKey())
                ->where('provider', $provider->value)
                ->where('account_id', $perfil->id)
                ->first();

            $conexao ??= new CloudConnection([
                'client_id' => $client->getKey(),
                'provider' => $provider,
                'account_id' => $perfil->id,
            ]);

            // Reconexão sem prompt=consent pode vir sem refresh token novo:
            // o antigo continua valendo.
            $refresh = $tokens->refreshToken ?? $conexao->refresh_token;

            $conexao->fill([
                'user_id' => $consentedBy?->getKey() ?? $conexao->user_id,
                'account_email' => $perfil->email,
                'account_name' => $perfil->name,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $refresh,
                'token_expires_at' => $tokens->expiresAt,
                'scopes' => $tokens->scopes,
                'status' => ConnectionStatus::Connected,
                'last_error' => null,
            ]);

            $conexao->forceFill([
                'token_refreshed_at' => now(),
                'consent_given_at' => now(),
                'consent_given_by' => $consentedBy?->getKey(),
            ]);

            $conexao->save();

            activity('CloudConnection')
                ->performedOn($conexao)
                ->causedBy($consentedBy)
                ->event('consent')
                ->withProperties([
                    'provedor' => $provider->value,
                    'conta' => $conexao->label(),
                    'escopos' => $tokens->scopes,
                    'token' => SecretMask::mask($tokens->accessToken),
                ])
                ->log('Conexão de nuvem autorizada pelo usuário');

            Log::info('Nuvem: conta conectada', [
                'provedor' => $provider->value,
                'client_id' => $client->getKey(),
                'conta' => $conexao->label(),
                'token' => SecretMask::mask($tokens->accessToken),
                'refresh_token_presente' => $refresh !== null,
            ]);

            return $conexao;
        });
    }
}
