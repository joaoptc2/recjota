<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Fixtures modeladas nas respostas reais da Google Drive API v3, do OAuth
 * 2.0 da Google, do Entra ID e do Microsoft Graph (tests/Fixtures/google e
 * tests/Fixtures/microsoft).
 */
trait CloudFixtures
{
    protected const GOOGLE_FILE_ID = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456';

    protected const ONEDRIVE_ITEM_ID = '01FOTOVITRINE00000000000000000000';

    protected function configureCloud(): void
    {
        config([
            'services.google.client_id' => '123456789012-abcdefghijklmnop.apps.googleusercontent.com',
            'services.google.client_secret' => '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mLog',
            'services.google.api_key' => 'AIzaSyChaveDaApiDaGoogle',
            'services.google.redirect_uri' => 'https://recjota.test/oauth/google/callback',
            'services.microsoft.client_id' => '11111111-2222-3333-4444-555555555555',
            'services.microsoft.client_secret' => 'segredoDaMicrosoftQueNuncaVazaEmLog',
            'services.microsoft.tenant' => 'common',
            'services.microsoft.redirect_uri' => 'https://recjota.test/oauth/microsoft/callback',
            'agency.cloud_temp.path' => sys_get_temp_dir().'/nuvem-tmp-'.uniqid('', true),
        ]);
    }

    /** @return array<string, mixed> */
    protected function cloudFixture(string $provedor, string $nome): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/{$provedor}/{$nome}.json"));

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $headers */
    protected function cloudResponse(string $provedor, string $nome, int $status = 200, array $headers = []): PromiseInterface
    {
        return Http::response($this->cloudFixture($provedor, $nome), $status, $headers);
    }

    protected function jpegBinary(): string
    {
        return (string) (new ImageManager(new Driver))
            ->create(1080, 1350)
            ->fill('#e11d48')
            ->toJpeg();
    }

    /** OAuth feliz da Google: code → token → userinfo. */
    protected function fakeGoogleOAuth(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => $this->cloudResponse('google', 'token'),
            'www.googleapis.com/oauth2/v3/userinfo' => $this->cloudResponse('google', 'userinfo'),
        ]);
    }

    /** OAuth feliz da Microsoft: code → token → /me. */
    protected function fakeMicrosoftOAuth(): void
    {
        Http::fake([
            'login.microsoftonline.com/common/oauth2/v2.0/token' => $this->cloudResponse('microsoft', 'token'),
            'graph.microsoft.com/v1.0/me*' => $this->cloudResponse('microsoft', 'me'),
        ]);
    }
}
