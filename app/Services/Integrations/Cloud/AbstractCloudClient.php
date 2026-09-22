<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Support\Enums\CloudProvider;
use App\Support\SecretMask;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * O que Google e Microsoft têm em comum: HTTP com timeout curto, conversão
 * de qualquer falha em CloudApiException e segredos mascarados em log.
 */
abstract class AbstractCloudClient implements CloudStorageInterface
{
    /** @var array<string, mixed> */
    protected array $config;

    /** @param  array<string, mixed>|null  $config  Sobrescreve config('services.<provedor>') (testes) */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.'.$this->provider()->configKey());
    }

    public function isConfigured(): bool
    {
        return (string) ($this->config['client_id'] ?? '') !== ''
            && (string) ($this->config['client_secret'] ?? '') !== ''
            && (string) ($this->config['redirect_uri'] ?? '') !== '';
    }

    /** @return array<int, string> */
    public function scopes(): array
    {
        return array_values((array) ($this->config['scopes'] ?? []));
    }

    protected function clientId(): string
    {
        return (string) ($this->config['client_id'] ?? '');
    }

    protected function clientSecret(): string
    {
        return (string) ($this->config['client_secret'] ?? '');
    }

    protected function redirectUri(): string
    {
        return (string) ($this->config['redirect_uri'] ?? '');
    }

    /**
     * Executa a chamada e devolve o JSON decodificado.
     *
     * @param  callable(PendingRequest): Response  $call
     * @param  array<int, string|null>  $secrets
     * @return array<string, mixed>
     *
     * @throws CloudApiException
     */
    protected function sendJson(callable $call, array $secrets, ?int $timeout = null): array
    {
        $response = $this->send($call, $secrets, $timeout);
        $json = $response->json();

        if (! is_array($json)) {
            throw CloudApiException::malformed(
                $this->provider(),
                'corpo não é JSON ('.SecretMask::scrub(mb_substr($response->body(), 0, 120), $secrets).')',
                $secrets,
            );
        }

        return $json;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  array<int, string|null>  $secrets
     *
     * @throws CloudApiException
     */
    protected function send(callable $call, array $secrets, ?int $timeout = null): Response
    {
        try {
            $response = $call($this->http($timeout));
        } catch (ConnectionException $e) {
            Log::warning($this->provider()->label().': falha de rede', [
                'erro' => SecretMask::scrub($e->getMessage(), $secrets),
            ]);

            throw CloudApiException::network($this->provider(), $e, $secrets);
        }

        if ($response->failed()) {
            $exception = CloudApiException::fromResponse($this->provider(), $response, $secrets);

            Log::warning($this->provider()->label().': erro da API', [
                'http' => $response->status(),
                'codigo' => $exception->apiCode,
                'permanente' => $exception->isPermanent(),
                'mensagem' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        return $response;
    }

    protected function http(?int $timeout = null): PendingRequest
    {
        $timeout ??= (int) ($this->config['timeout'] ?? 8);

        return Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(3, $timeout))
            ->withUserAgent('Recjota/1.0 (+'.config('app.url').')');
    }

    protected function downloadTimeout(): int
    {
        return (int) ($this->config['download_timeout'] ?? 40);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string|null>  $secrets
     */
    protected function requireString(array $payload, string $chave, array $secrets): string
    {
        $valor = $payload[$chave] ?? null;

        if (! is_string($valor) || $valor === '') {
            throw CloudApiException::malformed($this->provider(), sprintf('campo "%s" ausente', $chave), $secrets);
        }

        return $valor;
    }

    abstract public function provider(): CloudProvider;
}
