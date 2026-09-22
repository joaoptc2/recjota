<?php

declare(strict_types=1);

namespace App\Actions\Integrations;

use App\Models\CloudConnection;
use App\Models\User;
use App\Support\Enums\ConnectionStatus;

/**
 * Desconecta uma nuvem. Os assets importados continuam na biblioteca (com
 * miniatura), mas sem conexão o original não pode mais ser baixado: o post
 * que os usa falha na publicação com instrução para reconectar.
 */
class DisconnectCloudAccount
{
    public function __invoke(CloudConnection $conexao, ?User $quem): int
    {
        $afetados = $conexao->mediaAssets()->count();

        $conexao->fill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => ConnectionStatus::Revoked,
            'last_error' => 'Conexão desfeita pela equipe. Clique em "Reconectar" para voltar a usar arquivos desta conta.',
        ])->save();

        activity('CloudConnection')
            ->performedOn($conexao)
            ->causedBy($quem)
            ->event('disconnect')
            ->withProperties(['provedor' => $conexao->provider->value, 'conta' => $conexao->label(), 'assets_afetados' => $afetados])
            ->log('Conexão de nuvem desfeita');

        return $afetados;
    }
}
