<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\Instagram\InstagramClient;
use App\Support\DataObjects\InstagramConnectionData;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\SocialPlatform;
use App\Support\Exceptions\SocialAccountAlreadyLinked;
use App\Support\SecretMask;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conecta (ou reconecta) uma conta do Instagram a um cliente (Seção 7.1.1).
 *
 * Fluxo: code → token curto → token longo (60 dias) → /me → SocialAccount.
 * A conta é identificada por platform + external_id: reconectar a mesma conta
 * atualiza o registro existente (posts vinculados continuam válidos), em vez
 * de criar uma duplicata.
 */
class ConnectInstagramAccount
{
    /** Tempo total das chamadas do callback OAuth (R5: requisição < 10s). */
    public const CALLBACK_BUDGET_SECONDS = 8.0;

    public function __construct(private readonly InstagramClient $instagram) {}

    /**
     * Executa as trocas com a API e persiste.
     *
     * @throws InstagramApiException quando qualquer passo da API falha
     * @throws SocialAccountAlreadyLinked quando a conta pertence a outro cliente
     */
    public function fromAuthorizationCode(Client $client, string $code, ?User $consentedBy): SocialAccount
    {
        // Três chamadas dentro de uma requisição HTTP que a hospedagem derruba
        // em ~10s: o orçamento de tempo é repartido entre elas.
        $instagram = $this->instagram->withTimeBudget(self::CALLBACK_BUDGET_SECONDS);

        $curto = $instagram->exchangeCode($code);
        $longo = $instagram->exchangeForLongLived($curto->accessToken);
        $perfil = $instagram->me($longo->accessToken);

        $escopos = $curto->permissions !== [] ? $curto->permissions : $this->instagram->scopes();

        return $this->persist(new InstagramConnectionData(
            client: $client,
            profile: $perfil,
            tokens: $longo,
            scopes: $escopos,
            consentedBy: $consentedBy,
        ));
    }

    /** Grava a conexão já resolvida. Separado para facilitar testes e reuso. */
    public function persist(InstagramConnectionData $data): SocialAccount
    {
        return DB::transaction(function () use ($data): SocialAccount {
            // Fora do escopo de tenant de propósito: a unicidade de
            // platform+external_id vale para a agência inteira, e a resposta
            // certa para "conta já usada em outro cliente" é um erro claro.
            $conta = SocialAccount::query()
                ->withoutGlobalScope(ClientScope::class)
                ->withTrashed()
                ->where('platform', SocialPlatform::Instagram->value)
                ->where('external_id', $data->profile->igUserId)
                ->first();

            if ($conta !== null && $conta->client_id !== $data->client->getKey()) {
                Log::warning('Instagram: conta já vinculada a outro cliente', [
                    'conta' => $conta->handle(),
                    'client_id_atual' => $conta->client_id,
                    'client_id_pedido' => $data->client->getKey(),
                ]);

                throw SocialAccountAlreadyLinked::for($conta);
            }

            $conta ??= new SocialAccount([
                'client_id' => $data->client->getKey(),
                'platform' => SocialPlatform::Instagram,
                'external_id' => $data->profile->igUserId,
            ]);

            if ($conta->trashed()) {
                $conta->restore();
            }

            $conta->fill([
                'username' => $data->profile->username,
                'display_name' => $data->profile->name ?? $data->profile->username,
                'avatar_url' => $data->profile->profilePictureUrl,
                'account_type' => $data->profile->accountType,
                'access_token' => $data->tokens->accessToken,
                'token_expires_at' => $data->tokens->expiresAt ?? $this->instagram->defaultExpiry(),
                'scopes' => $data->scopes,
                'connection_status' => ConnectionStatus::Connected,
                'last_error' => null,
            ]);

            $conta->forceFill([
                'token_refreshed_at' => now(),
                'consent_given_at' => now(),
                'consent_given_by' => $data->consentedBy?->getKey(),
            ]);

            $conta->save();

            // Consentimento LGPD: quem autorizou, quando, com quais escopos.
            activity('SocialAccount')
                ->performedOn($conta)
                ->causedBy($data->consentedBy)
                ->event('consent')
                ->withProperties([
                    'plataforma' => SocialPlatform::Instagram->value,
                    'conta' => $conta->handle(),
                    'escopos' => $data->scopes,
                    'token' => SecretMask::mask($data->tokens->accessToken),
                ])
                ->log('Conexão autorizada pelo usuário');

            Log::info('Instagram: conta conectada', [
                'client_id' => $conta->client_id,
                'conta' => $conta->handle(),
                'token' => SecretMask::mask($data->tokens->accessToken),
                'expira_em' => $conta->token_expires_at?->toIso8601String(),
            ]);

            return $conta;
        });
    }
}
