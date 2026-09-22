<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * Fixtures modeladas nas respostas reais da Graph API (tests/Fixtures/instagram).
 */
trait InstagramFixtures
{
    protected function configureInstagram(): void
    {
        config([
            'services.instagram.app_id' => '1234567890',
            'services.instagram.app_secret' => 'segredo-do-app-que-nunca-vaza-em-log',
            'services.instagram.redirect_uri' => 'https://recjota.test/oauth/instagram/callback',
            'services.instagram.api_version' => 'v23.0',
        ]);
    }

    /** @return array<string, mixed> */
    protected function fixture(string $nome): array
    {
        $json = file_get_contents(base_path("tests/Fixtures/instagram/{$nome}.json"));

        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, string>  $headers */
    protected function fixtureResponse(string $nome, int $status = 200, array $headers = []): PromiseInterface
    {
        return Http::response($this->fixture($nome), $status, $headers);
    }

    protected function shortLivedToken(): string
    {
        return $this->fixture('oauth_access_token')['data'][0]['access_token'];
    }

    protected function longLivedToken(): string
    {
        return $this->fixture('long_lived_token')['access_token'];
    }

    protected function refreshedToken(): string
    {
        return $this->fixture('refresh_access_token')['access_token'];
    }

    /** Fluxo OAuth completo e feliz: code → curto → longo → /me. */
    protected function fakeHappyOAuth(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => $this->fixtureResponse('oauth_access_token'),
            'graph.instagram.com/v23.0/access_token*' => $this->fixtureResponse('long_lived_token'),
            'graph.instagram.com/v23.0/me*' => $this->fixtureResponse('me'),
        ]);
    }
}
