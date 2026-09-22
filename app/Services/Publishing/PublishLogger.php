<?php

declare(strict_types=1);

namespace App\Services\Publishing;

use App\Models\Post;
use App\Models\PublishLog;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Support\Enums\PublishStage;
use App\Support\SecretMask;
use Throwable;

/**
 * Grava cada chamada do motor em publish_logs (Seção 8). Tokens e segredos
 * passam pelo mesmo mascaramento do InstagramClient (SecretMask) ANTES de
 * serem serializados: o log é lido por gestores, não por operadores.
 */
class PublishLogger
{
    /** Chaves cujo valor é sempre mascarado, esteja onde estiver no payload. */
    private const SECRET_KEYS = ['access_token', 'client_secret', 'refresh_token', 'token', 'app_secret'];

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    public function success(Post $post, PublishStage $stage, array $request, ?array $response = null): PublishLog
    {
        return $this->write($post, $stage, $request, $response, true, null);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    public function failure(Post $post, PublishStage $stage, array $request, Throwable|string $error, ?array $response = null): PublishLog
    {
        $mensagem = $error instanceof Throwable ? $error->getMessage() : $error;

        // Erro da API sem corpo informado: guardamos o que a Graph API devolveu
        // (código, subcódigo, trace) — é o que o suporte da Meta pede.
        if ($response === null && $error instanceof InstagramApiException) {
            $response = ['error' => array_filter([
                'code' => $error->apiCode,
                'error_subcode' => $error->apiSubcode,
                'type' => $error->apiType,
                'http_status' => $error->httpStatus,
                'fbtrace_id' => $error->traceId,
                'error_user_msg' => $error->userMessage,
                'network' => $error->network ?: null,
            ], fn ($v) => $v !== null)];
        }

        return $this->write($post, $stage, $request, $response, false, $mensagem);
    }

    /**
     * @param  array<string, mixed>  $request
     * @param  array<string, mixed>|null  $response
     */
    private function write(Post $post, PublishStage $stage, array $request, ?array $response, bool $succeeded, ?string $error): PublishLog
    {
        $secrets = $this->secretsOf($post);

        return PublishLog::query()->create([
            'post_id' => $post->getKey(),
            // publish_attempts só sobe quando a tentativa falha, então a
            // tentativa em curso é sempre a seguinte ao contador.
            'attempt' => (int) $post->publish_attempts + 1,
            'stage' => $stage,
            'request' => $this->mask($request, $secrets),
            'response' => $response === null ? null : $this->mask($response, $secrets),
            'succeeded' => $succeeded,
            'error' => $error === null ? null : SecretMask::scrub($error, $secrets),
        ]);
    }

    /**
     * Mascara por chave conhecida e, por segurança, por valor: qualquer string
     * que contenha um segredo inteiro sai mascarada.
     *
     * @param  array<mixed>  $dados
     * @param  array<int, string|null>  $secrets
     * @return array<mixed>
     */
    public function mask(array $dados, array $secrets): array
    {
        $saida = [];

        foreach ($dados as $chave => $valor) {
            if (is_array($valor)) {
                $saida[$chave] = $this->mask($valor, $secrets);

                continue;
            }

            if (is_string($chave) && in_array(strtolower($chave), self::SECRET_KEYS, true) && is_string($valor)) {
                $saida[$chave] = SecretMask::mask($valor);

                continue;
            }

            $saida[$chave] = is_string($valor) ? SecretMask::scrub($valor, $secrets) : $valor;
        }

        return $saida;
    }

    /** @return array<int, string|null> */
    private function secretsOf(Post $post): array
    {
        return [
            $post->socialAccount?->access_token,
            $post->socialAccount?->refresh_token,
            (string) config('services.instagram.app_secret'),
        ];
    }
}
