<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Integrations\InstagramOAuthController;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Enums\AccountType;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\RoleName;
use App\Support\Enums\SocialPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Conexão de conta do Instagram por OAuth (Seção 7.1.1). O state na sessão é
 * o CSRF do fluxo: sem ele, qualquer link forjado conectaria uma conta alheia.
 */
class InstagramOAuthTest extends TestCase
{
    use InstagramFixtures;
    use RefreshDatabase;

    private Client $cliente;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->configureInstagram();

        $this->cliente = Client::factory()->configured()->create(['name' => 'Padaria Central']);
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
    }

    /** @return array<string, mixed> */
    private function sessaoOAuth(string $state = 'state-valido-1234567890', ?int $clientId = null): array
    {
        return [
            InstagramOAuthController::SESSION_KEY => [
                'state' => $state,
                'client_id' => $clientId ?? $this->cliente->getKey(),
                'social_account_id' => null,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ];
    }

    public function test_conectar_redireciona_para_o_instagram_com_state_amarrado_ao_cliente(): void
    {
        $resposta = $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.instagram.connect', $this->cliente));

        $resposta->assertRedirect();

        $destino = $resposta->headers->get('Location');
        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', (string) $destino);

        parse_str((string) parse_url((string) $destino, PHP_URL_QUERY), $query);

        $this->assertSame('1234567890', $query['client_id']);
        $this->assertSame('https://recjota.test/oauth/instagram/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame(
            'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments',
            $query['scope'],
        );

        $pendente = session(InstagramOAuthController::SESSION_KEY);
        $this->assertSame($query['state'], $pendente['state']);
        $this->assertSame($this->cliente->getKey(), $pendente['client_id']);
        $this->assertGreaterThanOrEqual(32, strlen($pendente['state']));
    }

    public function test_sem_credenciais_configuradas_explica_o_que_preencher_em_vez_de_quebrar(): void
    {
        config(['services.instagram.app_id' => null]);

        $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.instagram.connect', $this->cliente))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('instagram');

        $this->assertStringContainsString('IG_APP_ID', session('errors')->first('instagram'));
    }

    public function test_criador_sem_permissao_de_integracoes_recebe_403(): void
    {
        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->actingAsUser($criador)
            ->get(route('painel.integrations.instagram.connect', $this->cliente))
            ->assertForbidden();
    }

    public function test_gestor_sem_vinculo_com_o_cliente_recebe_403(): void
    {
        $outro = Client::factory()->configured()->create();

        $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.instagram.connect', $outro))
            ->assertForbidden();
    }

    public function test_callback_feliz_cria_a_conta_com_token_criptografado_e_consentimento(): void
    {
        $this->fakeHappyOAuth();

        $resposta = $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'AQD-codigo-de-autorizacao', 'state' => 'state-valido-1234567890']));

        $resposta->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHas('status')
            ->assertSessionMissing(InstagramOAuthController::SESSION_KEY);

        $this->assertStringContainsString('@recjota.demo', session('status'));

        // As três chamadas, na ordem e com os parâmetros que a Meta exige.
        Http::assertSentCount(3);
        Http::assertSentInOrder([
            fn (Request $r) => str_starts_with($r->url(), 'https://api.instagram.com/oauth/access_token')
                && $r->isForm()
                && $r['grant_type'] === 'authorization_code'
                && $r['client_id'] === '1234567890'
                && $r['client_secret'] === 'segredo-do-app-que-nunca-vaza-em-log'
                && $r['redirect_uri'] === 'https://recjota.test/oauth/instagram/callback'
                && $r['code'] === 'AQD-codigo-de-autorizacao',
            fn (Request $r) => str_starts_with($r->url(), 'https://graph.instagram.com/v23.0/access_token?')
                && $r['grant_type'] === 'ig_exchange_token'
                && $r['access_token'] === $this->shortLivedToken(),
            fn (Request $r) => str_starts_with($r->url(), 'https://graph.instagram.com/v23.0/me?')
                && str_contains($r['fields'], 'account_type')
                && $r['access_token'] === $this->longLivedToken(),
        ]);

        $conta = SocialAccount::query()->withoutGlobalScopes()->sole();

        $this->assertSame($this->cliente->getKey(), $conta->client_id);
        $this->assertSame(SocialPlatform::Instagram, $conta->platform);
        $this->assertSame('17841405793187218', $conta->external_id);
        $this->assertSame('recjota.demo', $conta->username);
        $this->assertSame('Recjota Demo', $conta->display_name);
        $this->assertStringStartsWith('https://scontent.cdninstagram.com/', (string) $conta->avatar_url);
        $this->assertSame(AccountType::Business, $conta->account_type);
        $this->assertSame(ConnectionStatus::Connected, $conta->connection_status);
        $this->assertSame([
            'instagram_business_basic',
            'instagram_business_content_publish',
            'instagram_business_manage_comments',
        ], $conta->scopes);

        // Token de longa duração, legível só via cast encrypted.
        $this->assertSame($this->longLivedToken(), $conta->access_token);
        $this->assertDatabaseMissing('social_accounts', ['access_token' => $this->longLivedToken()]);
        $this->assertDatabaseMissing('social_accounts', ['access_token' => $this->shortLivedToken()]);
        $this->assertNotSame($this->longLivedToken(), $conta->getRawOriginal('access_token'));

        // +60 dias (expires_in = 5183944s ≈ 59,99 dias).
        $this->assertEqualsWithDelta(60, $conta->daysUntilTokenExpires(), 1);
        $this->assertNotNull($conta->token_refreshed_at);

        // Consentimento LGPD: coluna + trilha de auditoria, sem token em claro.
        $this->assertNotNull($conta->consent_given_at);
        $this->assertSame($this->gestor->getKey(), $conta->consent_given_by);

        $consentimento = Activity::query()->where('event', 'consent')->sole();
        $this->assertSame($this->gestor->getKey(), $consentimento->causer_id);
        $this->assertSame($conta->getKey(), $consentimento->subject_id);
        $this->assertStringNotContainsString($this->longLivedToken(), (string) json_encode($consentimento->properties));

        // Serialização para o navegador nunca inclui o token.
        $this->assertArrayNotHasKey('access_token', $conta->toArray());
    }

    public function test_state_invalido_devolve_403_sem_chamar_a_api(): void
    {
        Http::fake();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth('state-guardado'))
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-forjado']))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_callback_sem_pedido_na_sessao_devolve_403_sem_chamar_a_api(): void
    {
        Http::fake();

        $this->actingAsUser($this->gestor)
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'qualquer']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_state_expirado_devolve_403(): void
    {
        Http::fake();

        $sessao = $this->sessaoOAuth();
        $sessao[InstagramOAuthController::SESSION_KEY]['expires_at'] = now()->subMinute()->timestamp;

        $this->actingAsUser($this->gestor)
            ->withSession($sessao)
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_state_so_vale_uma_vez(): void
    {
        $this->fakeHappyOAuth();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertRedirect();

        // Mesmo state de novo, sem passar pelo "conectar": rejeitado.
        $this->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertForbidden();

        Http::assertSentCount(3);
    }

    public function test_usuario_que_cancelou_no_instagram_recebe_mensagem_e_nada_e_chamado(): void
    {
        Http::fake();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', [
                'error' => 'access_denied',
                'error_reason' => 'user_denied',
                'error_description' => 'The user denied your request.',
                'state' => 'state-valido-1234567890',
            ]))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('instagram');

        $this->assertStringContainsString('The user denied your request.', session('errors')->first('instagram'));
        Http::assertNothingSent();
    }

    public function test_erro_da_api_vira_mensagem_acionavel_e_nunca_500(): void
    {
        Http::fake([
            'api.instagram.com/oauth/access_token' => $this->fixtureResponse('error_190', 400),
        ]);

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('instagram');

        $this->assertStringContainsString('Reconectar', session('errors')->first('instagram'));
        $this->assertDatabaseCount('social_accounts', 0);
    }

    public function test_falha_de_rede_tambem_vira_mensagem_na_tela(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out'));

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('instagram');
    }

    public function test_reconectar_atualiza_a_conta_existente_em_vez_de_duplicar(): void
    {
        $this->fakeHappyOAuth();

        $expirada = SocialAccount::factory()->expired()->create([
            'client_id' => $this->cliente->getKey(),
            'external_id' => '17841405793187218',
            'username' => 'nome.antigo',
            'last_error' => 'Token expirado',
        ]);

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente));

        $this->assertDatabaseCount('social_accounts', 1);

        $conta = $expirada->fresh();
        $this->assertSame(ConnectionStatus::Connected, $conta->connection_status);
        $this->assertSame('recjota.demo', $conta->username);
        $this->assertNull($conta->last_error);
        $this->assertSame($this->longLivedToken(), $conta->access_token);
        $this->assertTrue($conta->token_expires_at->isFuture());
    }

    public function test_conta_ja_ligada_a_outro_cliente_nao_e_movida(): void
    {
        $this->fakeHappyOAuth();

        $outro = Client::factory()->configured()->create(['name' => 'Cliente Sigiloso Ltda']);
        SocialAccount::factory()->create([
            'client_id' => $outro->getKey(),
            'external_id' => '17841405793187218',
        ]);

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessaoOAuth())
            ->get(route('oauth.instagram.callback', ['code' => 'abc', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('instagram');

        $this->assertStringContainsString('outro cliente', session('errors')->first('instagram'));
        // Um gestor deste cliente não tem por que saber o nome do outro.
        $this->assertStringNotContainsString('Sigiloso', session('errors')->first('instagram'));
        $this->assertSame($outro->getKey(), SocialAccount::withoutGlobalScopes()->sole()->client_id);
    }

    public function test_pagina_do_cliente_mostra_conectar_e_reconectar_conforme_a_permissao(): void
    {
        $expirada = SocialAccount::factory()->expired()->create([
            'client_id' => $this->cliente->getKey(),
            'username' => 'conta.expirada',
        ]);

        $this->actingAsUser($this->gestor)
            ->get(route('painel.clients.show', $this->cliente))
            ->assertOk()
            ->assertSee('Conectar Instagram')
            ->assertSee('Reconectar')
            ->assertSee(route('painel.integrations.instagram.connect', ['client' => $this->cliente, 'conta' => $expirada->ulid]), false);

        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->actingAsUser($criador)
            ->get(route('painel.clients.show', $this->cliente))
            ->assertOk()
            ->assertDontSee('Conectar Instagram')
            ->assertDontSee('Reconectar');
    }

    public function test_reconectar_via_query_exige_conta_do_proprio_cliente(): void
    {
        $outro = Client::factory()->configured()->create();
        $alheia = SocialAccount::factory()->expired()->create(['client_id' => $outro->getKey()]);

        $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.instagram.connect', ['client' => $this->cliente, 'conta' => $alheia->ulid]))
            ->assertNotFound();
    }
}
