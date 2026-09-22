<?php

declare(strict_types=1);

namespace App\Http\Controllers\Integrations;

use App\Actions\Integrations\ConnectInstagramAccount;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Scopes\ClientScope;
use App\Models\SocialAccount;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\Instagram\InstagramClient;
use App\Support\Enums\SocialPlatform;
use App\Support\Exceptions\SocialAccountAlreadyLinked;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fluxo OAuth do Instagram (Seção 7.1.1).
 *
 * redirect(): gera o state, guarda na sessão amarrado ao cliente e manda o
 * usuário para o Instagram. callback(): valida o state (CSRF do OAuth), delega
 * as trocas de token à Action e devolve o usuário à página do cliente com uma
 * mensagem — de sucesso ou de erro acionável. Nunca um 500 por erro da API.
 */
class InstagramOAuthController extends Controller
{
    public const SESSION_KEY = 'instagram_oauth';

    /** Uma autorização começada e nunca concluída expira em 15 minutos. */
    public const STATE_TTL_MINUTES = 15;

    public function __construct(private readonly InstagramClient $instagram) {}

    public function redirect(Request $request, Client $client): RedirectResponse
    {
        // Primeiro o cliente (403 vem da Policy), depois a permissão de integrações.
        $this->authorize('view', $client);

        $conta = $this->accountToReconnect($request, $client);

        if ($conta !== null) {
            $this->authorize('reconnect', $conta);
        } else {
            $this->authorize('create', SocialAccount::class);
        }

        if (! $this->instagram->isConfigured()) {
            return redirect()->route('painel.clients.show', $client)->withErrors([
                'instagram' => 'A integração com o Instagram ainda não foi configurada. Preencha IG_APP_ID, IG_APP_SECRET e IG_REDIRECT_URI no .env e tente de novo.',
            ]);
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'client_id' => $client->getKey(),
            'social_account_id' => $conta?->getKey(),
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ]);

        return redirect()->away($this->instagram->authorizationUrl($state));
    }

    public function callback(Request $request, ConnectInstagramAccount $conectar): RedirectResponse
    {
        // pull(): o state vale para uma única tentativa.
        $pendente = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (
            ! is_array($pendente)
            || ! is_string($state)
            || ! is_string($pendente['state'] ?? null)
            || ! hash_equals($pendente['state'], $state)
            || (int) ($pendente['expires_at'] ?? 0) < now()->timestamp
        ) {
            abort(403, 'A autorização do Instagram não corresponde a um pedido iniciado aqui ou expirou. Volte à página do cliente e clique em "Conectar Instagram" novamente.');
        }

        $client = Client::query()
            ->withoutGlobalScope(ClientScope::class)
            ->findOrFail((int) ($pendente['client_id'] ?? 0));

        $this->authorize('view', $client);
        $this->authorize('create', SocialAccount::class);

        $destino = redirect()->route('painel.clients.show', $client);

        if ($request->filled('error')) {
            $motivo = $request->string('error_description')->toString() ?: $request->string('error')->toString();

            return $destino->withErrors([
                'instagram' => sprintf('A autorização não foi concluída no Instagram (%s). Clique em "Conectar Instagram" e aceite todas as permissões.', $motivo),
            ]);
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $destino->withErrors([
                'instagram' => 'O Instagram não devolveu o código de autorização. Tente conectar novamente.',
            ]);
        }

        try {
            $conta = $conectar->fromAuthorizationCode($client, $code, $request->user());
        } catch (InstagramApiException $e) {
            return $destino->withErrors(['instagram' => $e->actionableMessage()]);
        } catch (SocialAccountAlreadyLinked $e) {
            return $destino->withErrors(['instagram' => $e->getMessage()]);
        }

        return $destino->with('status', sprintf(
            'Conta %s conectada ao cliente %s. O acesso é renovado automaticamente; validade atual até %s.',
            $conta->handle(),
            $client->name,
            display_date($conta->token_expires_at, $client),
        ));
    }

    /** Reconexão chega com ?conta={ulid}; a conta precisa pertencer a este cliente. */
    private function accountToReconnect(Request $request, Client $client): ?SocialAccount
    {
        $ulid = $request->query('conta');

        if (! is_string($ulid) || $ulid === '') {
            return null;
        }

        return SocialAccount::query()
            ->withoutGlobalScope(ClientScope::class)
            ->where('client_id', $client->getKey())
            ->where('platform', SocialPlatform::Instagram->value)
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
