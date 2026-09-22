<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Support\DataObjects\CloudFile;
use App\Support\DataObjects\CloudProfile;
use App\Support\DataObjects\CloudTokens;
use App\Support\Enums\CloudProvider;

/**
 * O que o sistema precisa de um armazenamento em nuvem (Seções 7.2 e 7.3):
 * autorizar, renovar, identificar quem autorizou, navegar/consultar itens e
 * baixar o original para a ponte de mídia. Uma implementação por provedor.
 */
interface CloudStorageInterface
{
    public function provider(): CloudProvider;

    /** Credenciais preenchidas no .env? Sem elas o botão de conectar explica o que falta. */
    public function isConfigured(): bool;

    public function authorizationUrl(string $state): string;

    /** @throws CloudApiException */
    public function exchangeCode(string $code): CloudTokens;

    /** @throws CloudApiException */
    public function refresh(string $refreshToken): CloudTokens;

    /** @throws CloudApiException */
    public function profile(string $accessToken): CloudProfile;

    /**
     * Itens de uma pasta (raiz quando null). No Google Drive, com drive.file,
     * só aparecem os arquivos que o usuário já escolheu pelo Picker.
     *
     * @return array<int, CloudFile>
     *
     * @throws CloudApiException
     */
    public function listChildren(string $accessToken, ?string $folderId = null): array;

    /** @throws CloudApiException */
    public function file(string $accessToken, string $fileId): CloudFile;

    /**
     * Baixa o conteúdo do arquivo direto para o caminho informado (stream,
     * sem carregar tudo em memória).
     *
     * @throws CloudApiException
     */
    public function download(string $accessToken, string $fileId, string $destino): void;
}
