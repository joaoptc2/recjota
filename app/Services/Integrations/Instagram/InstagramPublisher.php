<?php

declare(strict_types=1);

namespace App\Services\Integrations\Instagram;

use App\Models\SocialAccount;
use App\Services\Integrations\SocialPublisherInterface;
use App\Support\DataObjects\ContainerStatus;
use App\Support\DataObjects\MediaContainerData;
use App\Support\DataObjects\PublishingLimit;
use App\Support\Enums\ContainerStatusCode;

/**
 * Publicação no Instagram por cima do InstagramClient (Seção 7.1.4 e 7.1.5).
 *
 * Cada método recebe a SocialAccount porque token e ig-user-id (external_id)
 * vivem nela — o publisher não guarda estado. Toda falha lança
 * InstagramApiException; consulte isPermanent() antes de decidir por retry.
 */
class InstagramPublisher implements SocialPublisherInterface
{
    public function __construct(private readonly InstagramClient $client) {}

    /**
     * POST /{ig-user-id}/media
     *
     * Cria o container. Para imagem, o Instagram baixa image_url na hora; para
     * vídeo/reels o processamento é assíncrono e o status precisa ser
     * consultado com containerStatus() até FINISHED.
     *
     * @return string ID do container (creation_id)
     *
     * @throws InstagramApiException código 100/24/36 = mídia inválida (permanente)
     */
    public function createContainer(SocialAccount $account, MediaContainerData $data): string
    {
        $payload = $this->client->post(
            sprintf('/%s/media', $this->igUserId($account)),
            $this->token($account),
            $data->toParams(),
        );

        return $this->requireId($payload, 'container', $account);
    }

    /**
     * GET /{container-id}?fields=status_code,status
     *
     * @throws InstagramApiException
     */
    public function containerStatus(SocialAccount $account, string $containerId): ContainerStatus
    {
        $payload = $this->client->get($containerId, $this->token($account), [
            'fields' => 'status_code,status',
        ]);

        $codigo = ContainerStatusCode::tryFrom(strtoupper((string) ($payload['status_code'] ?? '')));

        if ($codigo === null) {
            throw InstagramApiException::malformed(
                sprintf('status_code desconhecido para o container %s', $containerId),
                [$this->token($account)],
            );
        }

        return new ContainerStatus(
            containerId: $containerId,
            code: $codigo,
            message: isset($payload['status']) ? (string) $payload['status'] : null,
        );
    }

    /**
     * POST /{ig-user-id}/media_publish (creation_id=container)
     *
     * Só chame com container FINISHED. Publicar o mesmo container duas vezes
     * falha na API; a idempotência do motor (Post::idempotencyKey) garante que
     * isto não acontece por reexecução de job.
     *
     * @return string ID da mídia publicada
     *
     * @throws InstagramApiException
     */
    public function publish(SocialAccount $account, string $containerId): string
    {
        $payload = $this->client->post(
            sprintf('/%s/media_publish', $this->igUserId($account)),
            $this->token($account),
            ['creation_id' => $containerId],
        );

        return $this->requireId($payload, 'mídia publicada', $account);
    }

    /**
     * GET /{media-id}?fields=permalink
     *
     * @return string|null Link público; null quando a API ainda não o expõe
     *
     * @throws InstagramApiException
     */
    public function permalink(SocialAccount $account, string $mediaId): ?string
    {
        $payload = $this->client->get($mediaId, $this->token($account), ['fields' => 'permalink']);

        $link = $payload['permalink'] ?? null;

        return is_string($link) && $link !== '' ? $link : null;
    }

    /**
     * POST /{media-id}/comments (message=...)
     *
     * @return string ID do comentário
     *
     * @throws InstagramApiException código 10 quando falta instagram_business_manage_comments
     */
    public function createComment(SocialAccount $account, string $mediaId, string $message): string
    {
        $payload = $this->client->post(
            sprintf('/%s/comments', $mediaId),
            $this->token($account),
            ['message' => $message],
        );

        return $this->requireId($payload, 'comentário', $account);
    }

    /**
     * GET /{ig-user-id}/content_publishing_limit?fields=quota_usage,config
     *
     * Resposta: {"data":[{"quota_usage":N,"config":{"quota_total":50,"quota_duration":86400}}]}
     * Ausência de dados vira cota zero/total configurado — nunca inventamos
     * um número maior do que a API informou.
     *
     * @throws InstagramApiException
     */
    public function publishingLimit(SocialAccount $account): PublishingLimit
    {
        $payload = $this->client->get(
            sprintf('/%s/content_publishing_limit', $this->igUserId($account)),
            $this->token($account),
            ['fields' => 'quota_usage,config'],
        );

        $dados = $payload['data'][0] ?? [];
        $config = is_array($dados['config'] ?? null) ? $dados['config'] : [];

        return new PublishingLimit(
            quotaUsage: (int) ($dados['quota_usage'] ?? 0),
            quotaTotal: (int) ($config['quota_total'] ?? config('agency.limits.publish_per_24h', 50)),
            quotaDurationSeconds: (int) ($config['quota_duration'] ?? 86400),
        );
    }

    // ---------------------------------------------------------------- interno

    private function igUserId(SocialAccount $account): string
    {
        return (string) $account->external_id;
    }

    private function token(SocialAccount $account): string
    {
        $token = (string) $account->access_token;

        if ($token === '') {
            throw new InstagramApiException(
                message: sprintf('A conta %s não tem token de acesso: reconecte-a.', $account->handle()),
                apiCode: 190,
            );
        }

        return $token;
    }

    /** @param  array<string, mixed>  $payload */
    private function requireId(array $payload, string $oQue, SocialAccount $account): string
    {
        $id = $payload['id'] ?? null;

        if ($id === null || $id === '') {
            throw InstagramApiException::malformed("resposta sem id de {$oQue}", [$this->token($account)]);
        }

        return (string) $id;
    }
}
