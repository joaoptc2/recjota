<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Support\DataObjects\CloudFile;
use App\Support\DataObjects\CloudProfile;
use App\Support\DataObjects\CloudTokens;
use App\Support\Enums\CloudProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;

/**
 * Google Drive com escopo drive.file (Seção 7.2).
 *
 * Com drive.file o app só enxerga arquivos que o usuário escolheu no Picker
 * (ou que o próprio app criou). Por isso listChildren() serve apenas para
 * mostrar "o que já foi escolhido"; a seleção de arquivos novos é sempre pelo
 * Picker, no navegador, com o token que o servidor entrega.
 */
class GoogleDriveClient extends AbstractCloudClient
{
    public const FILE_FIELDS = 'id,name,mimeType,size,modifiedTime,thumbnailLink';

    public function provider(): CloudProvider
    {
        return CloudProvider::GoogleDrive;
    }

    public function apiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    /** Número do projeto (prefixo do client_id), que o Picker exige em setAppId(). */
    public function appId(): string
    {
        return (string) strtok($this->clientId(), '-');
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            // offline + consent: só assim a Google devolve refresh_token, e é
            // ele que permite baixar o original no cron, sem ninguém logado.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
        ]);

        return $this->authUrl().'?'.$query;
    }

    public function exchangeCode(string $code): CloudTokens
    {
        $secrets = [$this->clientSecret(), $code];

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->asForm()->post($this->tokenUrl(), [
                'code' => $code,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
            ]),
            $secrets,
        );

        return $this->tokensFrom($payload, $secrets);
    }

    public function refresh(string $refreshToken): CloudTokens
    {
        $secrets = [$this->clientSecret(), $refreshToken];

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->asForm()->post($this->tokenUrl(), [
                'refresh_token' => $refreshToken,
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'grant_type' => 'refresh_token',
            ]),
            $secrets,
        );

        return $this->tokensFrom($payload, $secrets);
    }

    public function profile(string $accessToken): CloudProfile
    {
        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->apiUrl().'/oauth2/v3/userinfo'),
            [$accessToken],
        );

        return new CloudProfile(
            id: $this->requireString($payload, 'sub', [$accessToken]),
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            name: isset($payload['name']) ? (string) $payload['name'] : null,
        );
    }

    public function listChildren(string $accessToken, ?string $folderId = null): array
    {
        $pai = $folderId ?? 'root';

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->apiUrl().'/drive/v3/files', [
                'q' => sprintf("'%s' in parents and trashed = false", str_replace("'", "\\'", $pai)),
                'fields' => 'files('.self::FILE_FIELDS.')',
                'pageSize' => 100,
                'orderBy' => 'folder,name',
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ]),
            [$accessToken],
        );

        $arquivos = is_array($payload['files'] ?? null) ? $payload['files'] : [];

        return array_values(array_map(fn (array $item) => $this->fileFrom($item), array_filter($arquivos, 'is_array')));
    }

    public function file(string $accessToken, string $fileId): CloudFile
    {
        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->apiUrl().'/drive/v3/files/'.rawurlencode($fileId), [
                'fields' => self::FILE_FIELDS,
                'supportsAllDrives' => 'true',
            ]),
            [$accessToken],
        );

        return $this->fileFrom($payload);
    }

    public function download(string $accessToken, string $fileId, string $destino): void
    {
        $this->send(
            fn (PendingRequest $http) => $http->withToken($accessToken)
                ->sink($destino)
                ->get($this->apiUrl().'/drive/v3/files/'.rawurlencode($fileId), [
                    'alt' => 'media',
                    'supportsAllDrives' => 'true',
                ]),
            [$accessToken],
            $this->downloadTimeout(),
        );
    }

    // ---------------------------------------------------------------- interno

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string|null>  $secrets
     */
    private function tokensFrom(array $payload, array $secrets): CloudTokens
    {
        $expiresIn = isset($payload['expires_in']) ? (int) $payload['expires_in'] : 3600;
        $scope = isset($payload['scope']) ? (string) $payload['scope'] : '';

        return new CloudTokens(
            accessToken: $this->requireString($payload, 'access_token', $secrets),
            expiresAt: Carbon::now()->addSeconds($expiresIn),
            refreshToken: isset($payload['refresh_token']) && is_string($payload['refresh_token']) ? $payload['refresh_token'] : null,
            scopes: $scope === '' ? [] : (preg_split('/\s+/', trim($scope)) ?: []),
        );
    }

    /** @param  array<string, mixed>  $item */
    private function fileFrom(array $item): CloudFile
    {
        $mime = isset($item['mimeType']) ? (string) $item['mimeType'] : null;

        return new CloudFile(
            id: (string) ($item['id'] ?? ''),
            name: (string) ($item['name'] ?? 'sem nome'),
            mimeType: $mime,
            sizeBytes: isset($item['size']) ? (int) $item['size'] : null,
            isFolder: $mime === 'application/vnd.google-apps.folder',
            modifiedAt: isset($item['modifiedTime']) ? Carbon::parse((string) $item['modifiedTime']) : null,
            thumbnailUrl: isset($item['thumbnailLink']) ? (string) $item['thumbnailLink'] : null,
        );
    }

    private function authUrl(): string
    {
        return (string) ($this->config['auth_url'] ?? 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    private function tokenUrl(): string
    {
        return (string) ($this->config['token_url'] ?? 'https://oauth2.googleapis.com/token');
    }

    private function apiUrl(): string
    {
        return rtrim((string) ($this->config['api_url'] ?? 'https://www.googleapis.com'), '/');
    }
}
