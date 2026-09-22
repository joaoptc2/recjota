<?php

declare(strict_types=1);

namespace App\Services\Integrations\Instagram;

use App\Support\DataObjects\InstagramProfile;
use App\Support\DataObjects\InstagramTokens;
use App\Support\SecretMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP da Instagram API with Instagram Login (Seção 7.1.1 a 7.1.3).
 *
 * Responsabilidades:
 *  - OAuth: URL de autorização, troca de code, token de longa duração e
 *    renovação;
 *  - chamadas genéricas get()/post() na Graph API, usadas pelo
 *    InstagramPublisher;
 *  - registro dos cabeçalhos de uso (X-App-Usage, X-Business-Use-Case-Usage)
 *    com aviso quando qualquer bucket passa de 80%;
 *  - conversão de todo erro HTTP/rede em InstagramApiException.
 *
 * Tokens e app_secret nunca chegam ao log inteiros: passam por SecretMask.
 */
class InstagramClient
{
    public const USAGE_WARNING_THRESHOLD = 80;

    /** @var array<string, mixed> */
    private array $config;

    /** Instante (microtime) em que o orçamento de tempo acaba, ou null sem orçamento. */
    private ?float $deadline = null;

    /** @param  array<string, mixed>|null  $config  Sobrescreve config('services.instagram') (testes) */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.instagram');
    }

    /**
     * Cópia do cliente com um orçamento total de tempo para as próximas
     * chamadas, repartido entre elas: cada uma recebe no máximo o que sobrou.
     * Serve para fluxos dentro de uma requisição HTTP (callback OAuth), que
     * a hospedagem derruba em ~10s. Orçamento esgotado vira falha de rede,
     * com a mensagem acionável de sempre.
     */
    public function withTimeBudget(float $seconds): static
    {
        $clone = clone $this;
        $clone->deadline = microtime(true) + max(0.0, $seconds);

        return $clone;
    }

    // ------------------------------------------------------------------ OAuth

    /**
     * URL para onde o usuário é enviado para autorizar a conta (Seção 7.1.1).
     * O state é gerado e validado pelo controller (CSRF do fluxo OAuth).
     */
    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->appId(),
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(',', $this->scopes()),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return $this->authorizeUrl().'?'.$query;
    }

    /**
     * Troca o code do callback por um token de curta duração (~1h).
     *
     * POST https://api.instagram.com/oauth/access_token
     */
    public function exchangeCode(string $code): InstagramTokens
    {
        $secrets = [$this->appSecret(), $code];

        $response = $this->send(
            fn (PendingRequest $http) => $http->asForm()->post($this->oauthHost().'/oauth/access_token', [
                'client_id' => $this->appId(),
                'client_secret' => $this->appSecret(),
                'grant_type' => 'authorization_code',
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]),
            $secrets,
        );

        // A API with Instagram Login devolve {"data":[{...}]}; versões antigas
        // devolvem o objeto direto. Aceitamos os dois formatos.
        if (isset($response['data'][0]) && is_array($response['data'][0])) {
            $response = $response['data'][0];
        }

        $token = $this->requireString($response, 'access_token', $secrets);

        return new InstagramTokens(
            accessToken: $token,
            // Token curto vale cerca de 1h; a API não informa expires_in aqui.
            expiresAt: now()->addHour(),
            userId: isset($response['user_id']) ? (string) $response['user_id'] : null,
            permissions: $this->permissionsFrom($response),
        );
    }

    /**
     * Troca o token curto por um de longa duração (60 dias).
     *
     * GET /access_token?grant_type=ig_exchange_token&client_secret=...&access_token=...
     */
    public function exchangeForLongLived(string $shortLivedToken): InstagramTokens
    {
        $secrets = [$this->appSecret(), $shortLivedToken];

        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->graphUrl('/access_token'), [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => $this->appSecret(),
                'access_token' => $shortLivedToken,
            ]),
            $secrets,
        );

        return $this->longLivedTokensFrom($response, $secrets);
    }

    /**
     * Renova um token de longa duração ainda válido, com pelo menos 24h de
     * idade (Seção 7.1.3). Devolve token novo e novo prazo de 60 dias.
     *
     * GET /refresh_access_token?grant_type=ig_refresh_token&access_token=...
     */
    public function refreshLongLived(string $longLivedToken): InstagramTokens
    {
        $secrets = [$this->appSecret(), $longLivedToken];

        $response = $this->send(
            fn (PendingRequest $http) => $http->get($this->graphUrl('/refresh_access_token'), [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $longLivedToken,
            ]),
            $secrets,
        );

        return $this->longLivedTokensFrom($response, $secrets);
    }

    /**
     * Perfil da conta dona do token.
     *
     * GET /me?fields=id,user_id,username,name,account_type,profile_picture_url
     *
     * Com Instagram Login, `id` é o ID do usuário no app e `user_id` é o ID da
     * conta profissional — o que os endpoints de publicação esperam.
     */
    public function me(string $accessToken): InstagramProfile
    {
        $payload = $this->get('/me', $accessToken, [
            'fields' => 'id,user_id,username,name,account_type,profile_picture_url',
        ]);

        if (! isset($payload['id'])) {
            throw InstagramApiException::malformed('/me sem id', [$accessToken]);
        }

        return InstagramProfile::fromApi($payload);
    }

    // --------------------------------------------------------- chamadas Graph

    /**
     * GET genérico na Graph API (host versionado). O token vai como
     * access_token na query, como manda a documentação.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed> Corpo JSON decodificado
     *
     * @throws InstagramApiException
     */
    public function get(string $path, string $accessToken, array $query = []): array
    {
        $secrets = [$this->appSecret(), $accessToken];

        return $this->send(
            fn (PendingRequest $http) => $http->get($this->graphUrl($path), $query + ['access_token' => $accessToken]),
            $secrets,
        );
    }

    /**
     * POST genérico na Graph API (form-encoded, como a Graph API espera).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> Corpo JSON decodificado
     *
     * @throws InstagramApiException
     */
    public function post(string $path, string $accessToken, array $params = []): array
    {
        $secrets = [$this->appSecret(), $accessToken];

        return $this->send(
            fn (PendingRequest $http) => $http->asForm()->post($this->graphUrl($path), $params + ['access_token' => $accessToken]),
            $secrets,
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * Executa a chamada, registra uso e converte qualquer falha em exceção de
     * domínio. Os segredos são mascarados em qualquer mensagem que saia daqui.
     *
     * @param  callable(PendingRequest): Response  $call
     * @param  array<int, string|null>  $secrets
     * @return array<string, mixed>
     */
    private function send(callable $call, array $secrets): array
    {
        try {
            $response = $call($this->http());
        } catch (BudgetExhausted $e) {
            Log::warning('Instagram: orçamento de tempo esgotado antes da chamada');

            throw InstagramApiException::network($e, $secrets);
        } catch (ConnectionException $e) {
            Log::warning('Instagram: falha de rede', [
                'erro' => SecretMask::scrub($e->getMessage(), $secrets),
            ]);

            throw InstagramApiException::network($e, $secrets);
        }

        $this->recordUsage($response);

        if ($response->failed()) {
            $exception = InstagramApiException::fromResponse($response, $secrets);

            Log::warning('Instagram: erro da API', [
                'http' => $response->status(),
                'codigo' => $exception->apiCode,
                'subcodigo' => $exception->apiSubcode,
                'tipo' => $exception->apiType,
                'trace' => $exception->traceId,
                'permanente' => $exception->isPermanent(),
                'mensagem' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw InstagramApiException::malformed(
                'corpo não é JSON ('.SecretMask::scrub(mb_substr($response->body(), 0, 120), $secrets).')',
                $secrets,
            );
        }

        return $json;
    }

    /** @throws BudgetExhausted quando o orçamento de tempo já acabou */
    private function http(): PendingRequest
    {
        $timeout = (int) ($this->config['timeout'] ?? 8);

        if ($this->deadline !== null) {
            $restante = $this->deadline - microtime(true);

            if ($restante < 0.5) {
                throw new BudgetExhausted('O Instagram demorou demais para responder às chamadas anteriores.');
            }

            // Guzzle aceita fração de segundo; ceil evitaria isso.
            $timeout = min($timeout, (int) ceil($restante));
        }

        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(3, $timeout))
            ->withUserAgent('Recjota/1.0 (+'.config('app.url').')');
    }

    /**
     * Cabeçalhos de uso da Graph API. Registrados sempre que presentes; acima
     * de 80% em qualquer bucket vira warning, para agir antes do bloqueio.
     */
    private function recordUsage(Response $response): void
    {
        foreach (['X-App-Usage', 'X-Business-Use-Case-Usage'] as $header) {
            $raw = $response->header($header);

            if ($raw === '' || $raw === null) {
                continue;
            }

            $decoded = json_decode($raw, true);
            $usage = is_array($decoded) ? $decoded : ['raw' => $raw];

            Log::info('Instagram: uso da API', ['cabecalho' => $header, 'uso' => $usage]);

            $estourados = $this->bucketsAbove($usage, self::USAGE_WARNING_THRESHOLD);

            if ($estourados !== []) {
                Log::warning('Instagram: uso da API acima de '.self::USAGE_WARNING_THRESHOLD.'%', [
                    'cabecalho' => $header,
                    'buckets' => $estourados,
                ]);
            }
        }
    }

    /**
     * Percorre o JSON de uso e devolve os buckets percentuais acima do limite.
     * X-App-Usage: {"call_count":28,"total_time":25,"total_cputime":25}
     * X-Business-Use-Case-Usage: {"<id>":[{"type":"instagram","call_count":..,...}]}
     *
     * @param  array<mixed>  $usage
     * @return array<string, int|float>
     */
    private function bucketsAbove(array $usage, int $threshold, string $prefix = ''): array
    {
        $percentuais = ['call_count', 'total_time', 'total_cputime'];
        $acima = [];

        foreach ($usage as $chave => $valor) {
            $caminho = $prefix === '' ? (string) $chave : $prefix.'.'.$chave;

            if (is_array($valor)) {
                $acima += $this->bucketsAbove($valor, $threshold, $caminho);

                continue;
            }

            if (is_numeric($valor) && in_array((string) $chave, $percentuais, true) && $valor > $threshold) {
                $acima[$caminho] = $valor + 0;
            }
        }

        return $acima;
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string|null>  $secrets
     */
    private function longLivedTokensFrom(array $response, array $secrets): InstagramTokens
    {
        $token = $this->requireString($response, 'access_token', $secrets);
        $expiresIn = isset($response['expires_in']) ? (int) $response['expires_in'] : null;

        return new InstagramTokens(
            accessToken: $token,
            expiresAt: $expiresIn !== null && $expiresIn > 0
                ? now()->addSeconds($expiresIn)
                : $this->defaultExpiry(),
        );
    }

    public function defaultExpiry(): Carbon
    {
        return now()->addDays((int) ($this->config['token_ttl_days'] ?? 60));
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, string|null>  $secrets
     */
    private function requireString(array $response, string $key, array $secrets): string
    {
        $valor = $response[$key] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw InstagramApiException::malformed("campo {$key} ausente", $secrets);
        }

        return $valor;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<int, string>
     */
    private function permissionsFrom(array $response): array
    {
        $perms = $response['permissions'] ?? [];

        if (is_string($perms)) {
            $perms = explode(',', $perms);
        }

        return is_array($perms) ? array_values(array_filter(array_map('strval', $perms))) : [];
    }

    // --------------------------------------------------------------- config

    public function isConfigured(): bool
    {
        return $this->appId() !== '' && $this->appSecret() !== '' && $this->redirectUri() !== '';
    }

    public function appId(): string
    {
        return (string) ($this->config['app_id'] ?? '');
    }

    private function appSecret(): string
    {
        return (string) ($this->config['app_secret'] ?? '');
    }

    public function redirectUri(): string
    {
        return (string) ($this->config['redirect_uri'] ?? '');
    }

    /** @return array<int, string> */
    public function scopes(): array
    {
        $scopes = $this->config['scopes'] ?? [];

        return is_array($scopes) ? array_values($scopes) : [];
    }

    public function apiVersion(): string
    {
        return (string) ($this->config['api_version'] ?? 'v23.0');
    }

    public function graphUrl(string $path): string
    {
        $host = rtrim((string) ($this->config['graph_host'] ?? 'https://graph.instagram.com'), '/');

        return $host.'/'.$this->apiVersion().'/'.ltrim($path, '/');
    }

    private function oauthHost(): string
    {
        return rtrim((string) ($this->config['oauth_host'] ?? 'https://api.instagram.com'), '/');
    }

    private function authorizeUrl(): string
    {
        return (string) ($this->config['authorize_url'] ?? 'https://www.instagram.com/oauth/authorize');
    }
}
