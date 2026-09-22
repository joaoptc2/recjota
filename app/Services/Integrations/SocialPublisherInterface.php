<?php

declare(strict_types=1);

namespace App\Services\Integrations;

use App\Models\SocialAccount;
use App\Support\DataObjects\ContainerStatus;
use App\Support\DataObjects\MediaContainerData;
use App\Support\DataObjects\PublishingLimit;

/**
 * Contrato mínimo de publicação em rede social (Seção 7.1).
 *
 * O motor de publicação (Seção 8) conversa só com esta interface, para que
 * uma segunda plataforma entre sem reescrever o núcleo. O fluxo esperado:
 *
 *   1. createContainer()  → id do container (upload/processamento remoto)
 *   2. containerStatus()  → aguardar FINISHED (polling; ERROR/EXPIRED é falha)
 *   3. publish()          → id da mídia publicada
 *   4. permalink()        → link público para mostrar ao cliente
 *   5. createComment()    → primeiro comentário (hashtags), opcional
 *
 * publishingLimit() é consultado ANTES do passo 1: quando a cota das últimas
 * 24h acabou, o post é reagendado em vez de "tentar e falhar".
 *
 * Toda falha de rede/API é lançada como exceção de domínio da plataforma
 * (ex.: InstagramApiException) com isPermanent() para o motor decidir entre
 * retry com backoff e alerta acionável.
 */
interface SocialPublisherInterface
{
    /**
     * Cria o container de mídia (imagem, vídeo, reels, story, item ou pai de
     * carrossel). Para carrossel: crie cada item com isCarouselItem=true,
     * espere cada um ficar FINISHED e então crie o pai com children.
     *
     * @return string ID do container criado
     */
    public function createContainer(SocialAccount $account, MediaContainerData $data): string;

    /**
     * Estado atual do processamento de um container. Vídeo pode levar minutos;
     * o chamador faz polling com intervalo (nunca em loop apertado).
     */
    public function containerStatus(SocialAccount $account, string $containerId): ContainerStatus;

    /**
     * Publica um container FINISHED na conta.
     *
     * @return string ID da mídia publicada (media-id)
     */
    public function publish(SocialAccount $account, string $containerId): string;

    /**
     * Link público da mídia publicada. Pode ser null quando a plataforma ainda
     * não gerou o permalink (a UI mostra "indisponível", nunca inventa).
     */
    public function permalink(SocialAccount $account, string $mediaId): ?string;

    /**
     * Cria um comentário na mídia publicada (usado para o "primeiro
     * comentário" com hashtags).
     *
     * @return string ID do comentário criado
     */
    public function createComment(SocialAccount $account, string $mediaId, string $message): string;

    /** Cota de publicação da conta na janela corrente (Seção 7.1.5). */
    public function publishingLimit(SocialAccount $account): PublishingLimit;
}
