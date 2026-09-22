<?php

declare(strict_types=1);

namespace App\Services\Integrations\Instagram;

use App\Support\SecretMask;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Erro de domínio da Graph API do Instagram (Seção 7.1.6).
 *
 * A classificação isPermanent() é o que o motor de publicação usa para decidir
 * entre retry com backoff (transitório) e alerta acionável sem nova tentativa
 * (permanente). Códigos conforme a documentação de erros da Graph API:
 *
 *   permanente: 190 token inválido/expirado, 10 permissão negada,
 *               100 parâmetro inválido (mídia), 24 e 36 mídia rejeitada
 *   transitório: 4, 17, 32, 613 rate limit; 1, 2 erro genérico/indisponível;
 *                5xx e falha de rede
 */
class InstagramApiException extends RuntimeException
{
    /** @var array<int, int> */
    public const PERMANENT_CODES = [190, 10, 100, 24, 36];

    /** @var array<int, int> */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613];

    /** @var array<int, int> */
    public const TRANSIENT_CODES = [1, 2, 4, 17, 32, 613];

    /**
     * @param  array<int, string|null>  $secrets  Valores que jamais podem vazar na mensagem
     */
    public function __construct(
        string $message,
        public readonly ?int $apiCode = null,
        public readonly ?int $apiSubcode = null,
        public readonly ?string $apiType = null,
        public readonly ?int $httpStatus = null,
        public readonly ?string $traceId = null,
        public readonly ?string $userMessage = null,
        public readonly bool $network = false,
        ?Throwable $previous = null,
        array $secrets = [],
    ) {
        parent::__construct(SecretMask::scrub($message, $secrets), $apiCode ?? 0, $previous);
    }

    /**
     * Constrói a exceção a partir do corpo padrão {"error": {...}} da Graph API.
     *
     * @param  array<int, string|null>  $secrets
     */
    public static function fromResponse(Response $response, array $secrets = []): self
    {
        $erro = $response->json('error');
        $erro = is_array($erro) ? $erro : [];

        $mensagem = isset($erro['message']) ? (string) $erro['message'] : null;

        if ($mensagem === null || $mensagem === '') {
            $mensagem = sprintf('HTTP %d sem corpo de erro reconhecível', $response->status());
        }

        return new self(
            message: sprintf('Instagram API: %s', $mensagem),
            apiCode: isset($erro['code']) ? (int) $erro['code'] : null,
            apiSubcode: isset($erro['error_subcode']) ? (int) $erro['error_subcode'] : null,
            apiType: isset($erro['type']) ? (string) $erro['type'] : null,
            httpStatus: $response->status(),
            traceId: isset($erro['fbtrace_id']) ? (string) $erro['fbtrace_id'] : null,
            userMessage: isset($erro['error_user_msg']) ? (string) $erro['error_user_msg'] : null,
            secrets: $secrets,
        );
    }

    /**
     * A exceção original NÃO vai como $previous: a mensagem do Guzzle traz a
     * URL inteira, com access_token e client_secret na query, e qualquer
     * handler de log que percorra a cadeia de causas vazaria os dois.
     *
     * @param  array<int, string|null>  $secrets
     */
    public static function network(Throwable $causa, array $secrets = []): self
    {
        return new self(
            message: sprintf('Falha de rede ao falar com o Instagram (%s): %s', $causa::class, $causa->getMessage()),
            network: true,
            secrets: $secrets,
        );
    }

    /** @param  array<int, string|null>  $secrets */
    public static function malformed(string $detalhe, array $secrets = []): self
    {
        return new self(
            message: 'Resposta inesperada do Instagram: '.$detalhe,
            secrets: $secrets,
        );
    }

    /**
     * Erro permanente não merece nova tentativa: token revogado, permissão
     * negada ou mídia inválida só se resolvem com ação humana.
     */
    public function isPermanent(): bool
    {
        if ($this->network) {
            return false;
        }

        if ($this->httpStatus !== null && $this->httpStatus >= 500) {
            return false;
        }

        if ($this->apiCode !== null) {
            if (in_array($this->apiCode, self::PERMANENT_CODES, true)) {
                return true;
            }

            if (in_array($this->apiCode, self::TRANSIENT_CODES, true)) {
                return false;
            }
        }

        // Sem código conhecido: tratamos como transitório para dar ao backoff
        // a chance de resolver; o limite de tentativas cuida do resto.
        return false;
    }

    public function isTransient(): bool
    {
        return ! $this->isPermanent();
    }

    public function isRateLimit(): bool
    {
        return $this->apiCode !== null && in_array($this->apiCode, self::RATE_LIMIT_CODES, true);
    }

    /** Token inválido, expirado ou revogado: a conta precisa ser reconectada. */
    public function isTokenInvalid(): bool
    {
        return $this->apiCode === 190;
    }

    public function isPermissionDenied(): bool
    {
        return $this->apiCode === 10;
    }

    /**
     * Mensagem em pt-BR que diz o que aconteceu e o que fazer (Seção 14).
     * Nunca inclui token nem detalhes técnicos sem ação possível.
     */
    public function actionableMessage(): string
    {
        return match (true) {
            $this->network => 'Não foi possível falar com o Instagram agora. Verifique a conexão e tente de novo em alguns minutos.',
            $this->isTokenInvalid() => 'O Instagram não reconhece mais o acesso desta conta. Clique em "Reconectar" e autorize novamente.',
            $this->isPermissionDenied() => 'A conta do Instagram não concedeu as permissões necessárias. Reconecte e aceite todas as permissões pedidas.',
            $this->isRateLimit() => 'O Instagram limitou temporariamente as chamadas desta conta. Aguarde cerca de uma hora e tente de novo.',
            $this->apiCode === 24 || $this->apiCode === 36 => 'O Instagram recusou a mídia enviada. Confira formato, proporção e duração e envie um arquivo novo.',
            $this->apiCode === 100 => 'O Instagram recusou os dados enviados ('.($this->userMessage ?? 'parâmetro inválido').'). Revise a mídia e a legenda.',
            $this->httpStatus !== null && $this->httpStatus >= 500 => 'O Instagram está instável no momento. Tente de novo em alguns minutos.',
            default => 'O Instagram devolveu um erro inesperado'.($this->userMessage ? ': '.$this->userMessage : '').'. Tente de novo; se persistir, reconecte a conta.',
        };
    }
}
