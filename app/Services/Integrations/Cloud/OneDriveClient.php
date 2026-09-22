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
 * OneDrive pessoal e corporativo via Microsoft Graph (Seção 7.3), com OAuth
 * 2.0 no Entra ID (tenant `common` aceita os dois tipos de conta).
 *
 * A navegação usa /me/drive, que o Graph resolve para o OneDrive da conta
 * autenticada seja ela pessoal ou de trabalho — o mesmo código serve aos
 * dois casos, sem depender do File Picker e das diferenças de token entre
 * eles. Download por /me/drive/items/{id}/content.
 */
class OneDriveClient extends AbstractCloudClient
{
    public const ITEM_SELECT = 'id,name,size,file,folder,lastModifiedDateTime';

    public function provider(): CloudProvider
    {
        return CloudProvider::OneDrive;
    }

    public function authorizationUrl(string $state): string
    {
        $query = http_build_query([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'response_mode' => 'query',
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return $this->loginUrl().'/'.$this->tenant().'/oauth2/v2.0/authorize?'.$query;
    }

    public function exchangeCode(string $code): CloudTokens
    {
        $secrets = [$this->clientSecret(), $code];

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->asForm()->post($this->tokenEndpoint(), [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
                'grant_type' => 'authorization_code',
                'scope' => implode(' ', $this->scopes()),
            ]),
            $secrets,
        );

        return $this->tokensFrom($payload, $secrets);
    }

    public function refresh(string $refreshToken): CloudTokens
    {
        $secrets = [$this->clientSecret(), $refreshToken];

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->asForm()->post($this->tokenEndpoint(), [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
                'scope' => implode(' ', $this->scopes()),
            ]),
            $secrets,
        );

        return $this->tokensFrom($payload, $secrets);
    }

    public function profile(string $accessToken): CloudProfile
    {
        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->graphUrl().'/me', [
                '$select' => 'id,displayName,mail,userPrincipalName',
            ]),
            [$accessToken],
        );

        $email = $payload['mail'] ?? $payload['userPrincipalName'] ?? null;

        return new CloudProfile(
            id: $this->requireString($payload, 'id', [$accessToken]),
            email: is_string($email) ? $email : null,
            name: isset($payload['displayName']) ? (string) $payload['displayName'] : null,
        );
    }

    public function listChildren(string $accessToken, ?string $folderId = null): array
    {
        $caminho = $folderId === null
            ? '/me/drive/root/children'
            : '/me/drive/items/'.rawurlencode($folderId).'/children';

        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->graphUrl().$caminho, [
                '$select' => self::ITEM_SELECT,
                '$top' => 200,
                '$orderby' => 'name',
            ]),
            [$accessToken],
        );

        $itens = is_array($payload['value'] ?? null) ? $payload['value'] : [];

        return array_values(array_map(fn (array $item) => $this->fileFrom($item), array_filter($itens, 'is_array')));
    }

    public function file(string $accessToken, string $fileId): CloudFile
    {
        $payload = $this->sendJson(
            fn (PendingRequest $http) => $http->withToken($accessToken)->get($this->graphUrl().'/me/drive/items/'.rawurlencode($fileId), [
                '$select' => self::ITEM_SELECT,
            ]),
            [$accessToken],
        );

        return $this->fileFrom($payload);
    }

    public function download(string $accessToken, string $fileId, string $destino): void
    {
        // O Graph responde 302 para uma URL pré-assinada; o cliente segue o
        // redirecionamento (sem o Authorization, que o Guzzle descarta ao
        // trocar de host).
        $this->send(
            fn (PendingRequest $http) => $http->withToken($accessToken)
                ->sink($destino)
                ->get($this->graphUrl().'/me/drive/items/'.rawurlencode($fileId).'/content'),
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
        $file = is_array($item['file'] ?? null) ? $item['file'] : null;

        return new CloudFile(
            id: (string) ($item['id'] ?? ''),
            name: (string) ($item['name'] ?? 'sem nome'),
            mimeType: isset($file['mimeType']) ? (string) $file['mimeType'] : null,
            sizeBytes: isset($item['size']) ? (int) $item['size'] : null,
            isFolder: isset($item['folder']),
            modifiedAt: isset($item['lastModifiedDateTime']) ? Carbon::parse((string) $item['lastModifiedDateTime']) : null,
        );
    }

    private function tenant(): string
    {
        return (string) ($this->config['tenant'] ?? 'common');
    }

    private function loginUrl(): string
    {
        return rtrim((string) ($this->config['login_url'] ?? 'https://login.microsoftonline.com'), '/');
    }

    private function tokenEndpoint(): string
    {
        return $this->loginUrl().'/'.$this->tenant().'/oauth2/v2.0/token';
    }

    private function graphUrl(): string
    {
        return rtrim((string) ($this->config['graph_url'] ?? 'https://graph.microsoft.com/v1.0'), '/');
    }
}
