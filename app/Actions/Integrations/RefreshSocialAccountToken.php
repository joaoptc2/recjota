<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountExpired;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\Instagram\InstagramClient;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\RoleName;
use App\Support\Enums\TokenRefreshOutcome;
use App\Support\Enums\UserType;
use App\Support\SecretMask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Renova o token de longa duração de UMA conta (Seção 7.1.3).
 *
 * Erro permanente (190: token expirado/revogado; 10: permissão retirada)
 * marca a conta como expirada e avisa os gestores — só um humano resolve, e
 * quanto antes souber, menos posts agendados falham. Erro transitório é
 * adiado para a próxima execução diária sem alarde.
 */
class RefreshSocialAccountToken
{
    public function __construct(private readonly InstagramClient $instagram) {}

    public function __invoke(SocialAccount $conta): TokenRefreshOutcome
    {
        $tokenAtual = (string) $conta->access_token;

        try {
            $novo = $this->instagram->refreshLongLived($tokenAtual);
        } catch (InstagramApiException $e) {
            return $this->handleFailure($conta, $e);
        }

        $conta->fill([
            'access_token' => $novo->accessToken,
            'token_expires_at' => $novo->expiresAt ?? $this->instagram->defaultExpiry(),
            'last_error' => null,
        ]);
        $conta->forceFill(['token_refreshed_at' => now()]);
        $conta->save();

        Log::info('Instagram: token renovado', [
            'conta' => $conta->handle(),
            'client_id' => $conta->client_id,
            'token' => SecretMask::mask($novo->accessToken),
            'expira_em' => $conta->token_expires_at?->toIso8601String(),
        ]);

        return TokenRefreshOutcome::Refreshed;
    }

    private function handleFailure(SocialAccount $conta, InstagramApiException $e): TokenRefreshOutcome
    {
        if (! $e->isPermanent()) {
            Log::warning('Instagram: renovação de token adiada', [
                'conta' => $conta->handle(),
                'client_id' => $conta->client_id,
                'erro' => $e->getMessage(),
            ]);

            return TokenRefreshOutcome::Deferred;
        }

        $conta->fill([
            'connection_status' => ConnectionStatus::Expired,
            'last_error' => $e->actionableMessage(),
        ]);
        $conta->save();

        Log::error('Instagram: token não pôde ser renovado; conta marcada como expirada', [
            'conta' => $conta->handle(),
            'client_id' => $conta->client_id,
            'codigo' => $e->apiCode,
            'erro' => $e->getMessage(),
        ]);

        $this->notifyManagers($conta, $e);

        return TokenRefreshOutcome::Expired;
    }

    /**
     * Gestores vinculados ao cliente; sem gestor, avisa owner/admin da agência
     * para que a conta expirada nunca fique sem dono.
     */
    private function notifyManagers(SocialAccount $conta, InstagramApiException $e): void
    {
        // Cliente arquivado (soft delete) não carrega pela relação: cai no
        // fallback para owner/admin em vez de quebrar o comando inteiro.
        $gestores = $conta->client?->users()
            ->where('users.type', UserType::Agency->value)
            ->where('users.is_active', true)
            ->where('client_user.role', RoleName::Gestor->value)
            ->get() ?? collect();

        if ($gestores->isEmpty()) {
            $gestores = User::query()
                ->where('type', UserType::Agency->value)
                ->where('is_active', true)
                ->role([RoleName::Owner->value, RoleName::Admin->value])
                ->get();
        }

        if ($gestores->isEmpty()) {
            return;
        }

        Notification::send($gestores, new SocialAccountExpired($conta, $e->actionableMessage()));
    }
}
