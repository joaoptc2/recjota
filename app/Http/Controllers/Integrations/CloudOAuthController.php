<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\ConnectCloudAccount;
use App\Actions\Integrations\DisconnectCloudAccount;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\Scopes\ClientScope;
use App\Services\Integrations\Cloud\CloudApiException;
use App\Services\Integrations\Cloud\CloudStorageRegistry;
use App\Services\Integrations\Cloud\CloudTokenManager;
use App\Support\Enums\CloudProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * OAuth de Google Drive e OneDrive (Seções 7.2 e 7.3), no mesmo desenho do
 * Instagram: state na sessão amarrado ao cliente, callback com caminho fixo
 * (cadastrado no console do provedor), erro da API vira mensagem na tela.
 *
 * token(): entrega ao navegador um access token válido para o Google Picker.
 * É o único lugar em que um token sai do servidor, e sai só para quem tem
 * permissão de mídia no cliente — o Picker não funciona sem ele.
 */
class CloudOAuthController extends Controller
{
    public const SESSION_KEY = 'cloud_oauth';

    public const STATE_TTL_MINUTES = 15;

    public function __construct(
        private readonly CloudStorageRegistry $registry,
        private readonly CloudTokenManager $tokens,
    ) {}

    public function redirect(Request $request, string $provider, Client $client): RedirectResponse
    {
        $provedor = $this->providerOr404($provider);

        $this->authorize('view', $client);
        $this->authorize('create', CloudConnection::class);

        $servico = $this->registry->for($provedor);

        if (! $servico->isConfigured()) {
            return redirect()->route('painel.clients.show', $client)->withErrors([
                'cloud' => sprintf(
                    'A integração com o %s ainda não foi configurada. Preencha %s no .env e tente de novo.',
                    $provedor->label(),
                    $provedor === CloudProvider::GoogleDrive ? 'GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_API_KEY e GOOGLE_REDIRECT_URI' : 'MS_CLIENT_ID, MS_CLIENT_SECRET e MS_REDIRECT_URI',
                ),
            ]);
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'provider' => $provedor->value,
            'client_id' => $client->getKey(),
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ]);

        return redirect()->away($servico->authorizationUrl($state));
    }

    public function callback(Request $request, string $provider, ConnectCloudAccount $conectar): RedirectResponse
    {
        $provedor = $this->providerOr404($provider);
        $pendente = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (
            ! is_array($pendente)
            || ! is_string($state)
            || ! is_string($pendente['state'] ?? null)
            || ! hash_equals($pendente['state'], $state)
            || ($pendente['provider'] ?? null) !== $provedor->value
            || (int) ($pendente['expires_at'] ?? 0) < now()->timestamp
        ) {
            abort(403, sprintf('A autorização do %s não corresponde a um pedido iniciado aqui ou expirou. Volte à página do cliente e clique em "Conectar" novamente.', $provedor->label()));
        }

        $client = Client::query()
            ->withoutGlobalScope(ClientScope::class)
            ->findOrFail((int) ($pendente['client_id'] ?? 0));

        $this->authorize('view', $client);
        $this->authorize('create', CloudConnection::class);

        $destino = redirect()->route('painel.clients.show', $client);

        if ($request->filled('error')) {
            $motivo = $request->string('error_description')->toString() ?: $request->string('error')->toString();

            return $destino->withErrors([
                'cloud' => sprintf('A autorização não foi concluída no %s (%s). Clique em "Conectar" e aceite todas as permissões.', $provedor->label(), Str::limit($motivo, 160)),
            ]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $destino->withErrors([
                'cloud' => sprintf('O %s não devolveu o código de autorização. Tente conectar novamente.', $provedor->label()),
            ]);
        }

        try {
            $conexao = $conectar->fromAuthorizationCode($client, $provedor, $code, $request->user());
        } catch (CloudApiException $e) {
            return $destino->withErrors(['cloud' => $e->actionableMessage()]);
        }

        return $destino->with('status', sprintf(
            '%s conectado ao cliente %s (%s). Agora os arquivos dessa conta podem entrar na biblioteca.',
            $provedor->label(),
            $client->name,
            $conexao->label(),
        ));
    }

    public function disconnect(Request $request, CloudConnection $connection, DisconnectCloudAccount $desconectar): RedirectResponse
    {
        $this->authorize('delete', $connection);

        $afetados = $desconectar($connection, $request->user());
        $client = Client::query()->withoutGlobalScope(ClientScope::class)->findOrFail($connection->client_id);

        return redirect()->route('painel.clients.show', $client)->with('status', $afetados > 0
            ? sprintf('%s desconectado. %d arquivo(s) importado(s) continuam na biblioteca, mas só publicam depois de reconectar.', $connection->provider->label(), $afetados)
            : sprintf('%s desconectado.', $connection->provider->label()));
    }

    /** Token para o Google Picker (Seção 7.2). Curto (1h), renovado se preciso. */
    public function token(CloudConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);
        abort_unless($connection->provider === CloudProvider::GoogleDrive, 404);

        if ($connection->needsReconnection()) {
            return response()->json([
                'erro' => $connection->last_error ?? 'A conexão precisa ser refeita. Clique em "Reconectar" na página do cliente.',
            ], 409);
        }

        try {
            $token = $this->tokens->accessToken($connection);
        } catch (CloudApiException $e) {
            return response()->json(['erro' => $e->actionableMessage()], 409);
        }

        $google = $this->registry->for(CloudProvider::GoogleDrive);

        return response()
            ->json([
                'access_token' => $token,
                'expires_at' => $connection->fresh()?->token_expires_at?->toIso8601String(),
                'api_key' => method_exists($google, 'apiKey') ? $google->apiKey() : null,
                'app_id' => method_exists($google, 'appId') ? $google->appId() : null,
            ])
            ->header('Cache-Control', 'no-store');
    }

    private function providerOr404(string $slug): CloudProvider
    {
        $provedor = CloudProvider::fromSlug($slug);

        abort_if($provedor === null, 404);

        return $provedor;
    }
}
