<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Support\Enums\CloudProvider;
use App\Support\SecretMask;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Erro de domínio das APIs de nuvem (Google Drive, Microsoft Graph).
 *
 * A classificação permanente/transitória segue a mesma lógica do Instagram:
 * token revogado, consentimento retirado ou arquivo apagado precisam de um
 * humano; rede, 5xx e rate limit merecem nova tentativa.
 */
class CloudApiException extends RuntimeException
{
    /** @param  array<int, string|null>  $secrets  Valores que jamais podem vazar na mensagem */
    public function __construct(
        string $message,
        public readonly CloudProvider $provider,
        public readonly ?int $httpStatus = null,
        public readonly ?string $apiCode = null,
        public readonly bool $network = false,
        array $secrets = [],
    ) {
        parent::__construct(SecretMask::scrub($message, $secrets));
    }

    /** @param  array<int, string|null>  $secrets */
    public static function fromResponse(CloudProvider $provider, Response $response, array $secrets = []): self
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // Google: {"error": {"code": 403, "message": "...", "status": "PERMISSION_DENIED"}}
        // OAuth:  {"error": "invalid_grant", "error_description": "..."}
        // Graph:  {"error": {"code": "itemNotFound", "message": "..."}}
        $erro = $json['error'] ?? null;
        $codigo = null;
        $mensagem = null;

        if (is_array($erro)) {
            $codigo = isset($erro['status']) ? (string) $erro['status'] : (isset($erro['code']) ? (string) $erro['code'] : null);
            $mensagem = isset($erro['message']) ? (string) $erro['message'] : null;
        } elseif (is_string($erro)) {
            $codigo = $erro;
            $mensagem = isset($json['error_description']) ? (string) $json['error_description'] : $erro;
        }

        if ($mensagem === null || $mensagem === '') {
            $mensagem = sprintf('HTTP %d sem corpo de erro reconhecível', $response->status());
        }

        return new self(
            message: sprintf('%s: %s', $provider->label(), $mensagem),
            provider: $provider,
            httpStatus: $response->status(),
            apiCode: $codigo,
            secrets: $secrets,
        );
    }

    /** @param  array<int, string|null>  $secrets */
    public static function network(CloudProvider $provider, \Throwable $causa, array $secrets = []): self
    {
        // Sem $previous: a mensagem do Guzzle carrega a URL com o token.
        return new self(
            message: sprintf('Falha de rede ao falar com o %s (%s): %s', $provider->label(), $causa::class, $causa->getMessage()),
            provider: $provider,
            network: true,
            secrets: $secrets,
        );
    }

    /** @param  array<int, string|null>  $secrets */
    public static function malformed(CloudProvider $provider, string $detalhe, array $secrets = []): self
    {
        return new self(sprintf('Resposta inesperada do %s: %s', $provider->label(), $detalhe), $provider, secrets: $secrets);
    }

    /** Token revogado, consentimento retirado ou refresh token vencido: só reconectando. */
    public function isAuthorizationLost(): bool
    {
        if ($this->apiCode !== null && in_array($this->apiCode, ['invalid_grant', 'invalid_token', 'unauthorized_client', 'UNAUTHENTICATED', 'InvalidAuthenticationToken'], true)) {
            return true;
        }

        return $this->httpStatus === 401;
    }

    /** Arquivo apagado ou acesso a ele removido no provedor. */
    public function isFileGone(): bool
    {
        return $this->httpStatus === 404
            || in_array($this->apiCode, ['itemNotFound', 'NOT_FOUND'], true);
    }

    public function isPermanent(): bool
    {
        if ($this->network) {
            return false;
        }

        if ($this->httpStatus !== null && $this->httpStatus >= 500) {
            return false;
        }

        if ($this->httpStatus === 429) {
            return false;
        }

        return $this->isAuthorizationLost() || $this->isFileGone() || $this->httpStatus === 403;
    }

    /** Mensagem em pt-BR que diz o que aconteceu e o que fazer. */
    public function actionableMessage(): string
    {
        $nome = $this->provider->label();

        return match (true) {
            $this->network => sprintf('Não foi possível falar com o %s agora. Verifique a conexão e tente de novo em alguns minutos.', $nome),
            $this->isAuthorizationLost() => sprintf('O %s não reconhece mais o acesso desta conexão. Clique em "Reconectar" e autorize novamente.', $nome),
            $this->isFileGone() => sprintf('O arquivo não existe mais no %s ou o acesso a ele foi removido. Escolha o arquivo de novo.', $nome),
            $this->httpStatus === 403 => sprintf('O %s negou o acesso a este arquivo. Reconecte a conta aceitando todas as permissões ou escolha o arquivo pelo seletor.', $nome),
            $this->httpStatus === 429 => sprintf('O %s limitou temporariamente as chamadas. Aguarde alguns minutos e tente de novo.', $nome),
            $this->httpStatus !== null && $this->httpStatus >= 500 => sprintf('O %s está instável no momento. Tente de novo em alguns minutos.', $nome),
            default => sprintf('O %s devolveu um erro inesperado. Tente de novo; se persistir, reconecte a conta.', $nome),
        };
    }
}
