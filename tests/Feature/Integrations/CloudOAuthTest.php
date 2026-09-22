<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Http\Controllers\Integrations\CloudOAuthController;
use App\Models\Client;
use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\Enums\CloudProvider;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\MediaSource;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Conexão de Google Drive e OneDrive por OAuth (Seções 7.2 e 7.3). Mesmo
 * desenho do Instagram: state na sessão, tokens criptografados, consentimento
 * registrado, erro da API vira mensagem na tela.
 */
class CloudOAuthTest extends TestCase
{
    use CloudFixtures;
    use RefreshDatabase;

    private Client $cliente;

    private User $gestor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->configureCloud();

        $this->cliente = Client::factory()->configured()->create(['name' => 'Padaria Central']);
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('agency.cloud_temp.path'));

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function sessao(CloudProvider $provedor, string $state = 'state-valido-1234567890'): array
    {
        return [
            CloudOAuthController::SESSION_KEY => [
                'state' => $state,
                'provider' => $provedor->value,
                'client_id' => $this->cliente->getKey(),
                'expires_at' => now()->addMinutes(10)->timestamp,
            ],
        ];
    }

    public function test_conectar_google_redireciona_com_drive_file_offline_e_state_na_sessao(): void
    {
        $resposta = $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.cloud.connect', ['provider' => 'google', 'client' => $this->cliente]));

        $resposta->assertRedirect();
        $url = $resposta->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('auth/drive.file', $query['scope']);
        $this->assertStringNotContainsString('auth/drive ', $query['scope'].' ');
        $this->assertSame('https://recjota.test/oauth/google/callback', $query['redirect_uri']);

        $pendente = session(CloudOAuthController::SESSION_KEY);
        $this->assertSame($query['state'], $pendente['state']);
        $this->assertSame('google_drive', $pendente['provider']);
        $this->assertSame($this->cliente->getKey(), $pendente['client_id']);
    }

    public function test_conectar_microsoft_usa_o_tenant_common_com_offline_access_e_sites_read_all(): void
    {
        $resposta = $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.cloud.connect', ['provider' => 'microsoft', 'client' => $this->cliente]));

        $url = $resposta->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringContainsString('offline_access', $query['scope']);
        $this->assertStringContainsString('Sites.Read.All', $query['scope']);
        $this->assertStringContainsString('Files.Read', $query['scope']);
    }

    public function test_provedor_desconhecido_e_404_e_sem_credenciais_explica_o_que_preencher(): void
    {
        $this->actingAsUser($this->gestor)
            ->get('/painel/integracoes/nuvem/dropbox/conectar/'.$this->cliente->ulid)
            ->assertNotFound();

        config(['services.google.client_secret' => '']);

        $this->actingAsUser($this->gestor)
            ->get(route('painel.integrations.cloud.connect', ['provider' => 'google', 'client' => $this->cliente]))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('cloud');

        $this->assertStringContainsString('GOOGLE_CLIENT_ID', session('errors')->first('cloud'));
    }

    public function test_criador_e_usuario_do_portal_nao_conectam_nuvem(): void
    {
        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);
        $clienteAdmin = $this->userWithRole(RoleName::ClientAdmin, $this->cliente);

        $this->actingAsUser($criador)
            ->get(route('painel.integrations.cloud.connect', ['provider' => 'google', 'client' => $this->cliente]))
            ->assertForbidden();

        // Usuário do portal nem chega ao painel: o middleware o devolve ao portal.
        $resposta = $this->actingAsUser($clienteAdmin)
            ->get(route('painel.integrations.cloud.connect', ['provider' => 'google', 'client' => $this->cliente]));

        $this->assertContains($resposta->getStatusCode(), [302, 403]);
        $this->assertSame(0, CloudConnection::withoutGlobalScopes()->count());
    }

    public function test_callback_google_cria_a_conexao_com_tokens_criptografados_e_consentimento(): void
    {
        $this->fakeGoogleOAuth();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'code' => '4/0AbCd', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHas('status');

        $conexao = CloudConnection::withoutGlobalScopes()->sole();

        $this->assertSame(CloudProvider::GoogleDrive, $conexao->provider);
        $this->assertSame($this->cliente->getKey(), $conexao->client_id);
        $this->assertSame('109876543210987654321', $conexao->account_id);
        $this->assertSame('maria@padariacentral.com.br', $conexao->account_email);
        $this->assertSame(ConnectionStatus::Connected, $conexao->status);
        $this->assertStringStartsWith('ya29.', $conexao->access_token);
        $this->assertStringStartsWith('1//', $conexao->refresh_token);
        $this->assertContains('https://www.googleapis.com/auth/drive.file', $conexao->scopes);
        $this->assertTrue($conexao->token_expires_at->between(now()->addMinutes(55), now()->addMinutes(61)));
        $this->assertSame($this->gestor->getKey(), $conexao->consent_given_by);

        // Criptografado no banco e ausente de qualquer serialização.
        $bruto = DB::table('cloud_connections')->where('id', $conexao->getKey())->first();
        $this->assertStringNotContainsString('ya29.', (string) $bruto->access_token);
        $this->assertStringNotContainsString('1//', (string) $bruto->refresh_token);
        $this->assertArrayNotHasKey('access_token', $conexao->toArray());

        // Troca de code usa o client_secret e o redirect cadastrado.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token')
            && $r['grant_type'] === 'authorization_code'
            && $r['code'] === '4/0AbCd'
            && $r['redirect_uri'] === 'https://recjota.test/oauth/google/callback');

        $consentimento = Activity::query()->where('event', 'consent')->where('subject_type', CloudConnection::class)->sole();
        $this->assertSame($this->gestor->getKey(), $consentimento->causer_id);
        $this->assertStringNotContainsString($conexao->access_token, json_encode($consentimento->properties));

        // Segredos nunca chegam ao activity log nem ao cliente HTTP.
        $this->assertStringNotContainsString('GOCSPX', json_encode($consentimento->properties));
    }

    public function test_callback_microsoft_cria_a_conexao_com_o_email_do_graph(): void
    {
        $this->fakeMicrosoftOAuth();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::OneDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'microsoft', 'code' => 'M.C5.code', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHas('status');

        $conexao = CloudConnection::withoutGlobalScopes()->sole();

        $this->assertSame(CloudProvider::OneDrive, $conexao->provider);
        $this->assertSame('48d31887-5fad-4d73-a9f5-3c356e68a038', $conexao->account_id);
        $this->assertSame('joao@agencia.onmicrosoft.com', $conexao->account_email);
        $this->assertStringStartsWith('EwB', $conexao->access_token);
        $this->assertStringStartsWith('M.C5', $conexao->refresh_token);
        $this->assertContains('Sites.Read.All', $conexao->scopes);
    }

    public function test_state_invalido_expirado_ou_de_outro_provedor_devolve_403_sem_chamar_a_api(): void
    {
        Http::fake();

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'code' => 'x', 'state' => 'forjado']))
            ->assertForbidden();

        // State do Google usado no callback da Microsoft: não vale.
        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'microsoft', 'code' => 'x', 'state' => 'state-valido-1234567890']))
            ->assertForbidden();

        $expirada = $this->sessao(CloudProvider::GoogleDrive);
        $expirada[CloudOAuthController::SESSION_KEY]['expires_at'] = now()->subMinute()->timestamp;

        $this->actingAsUser($this->gestor)
            ->withSession($expirada)
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'code' => 'x', 'state' => 'state-valido-1234567890']))
            ->assertForbidden();

        Http::assertNothingSent();
        $this->assertSame(0, CloudConnection::withoutGlobalScopes()->count());
    }

    public function test_usuario_que_cancelou_ou_api_com_erro_recebe_mensagem_e_nunca_500(): void
    {
        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'error' => 'access_denied', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('cloud');

        $this->assertStringContainsString('não foi concluída', session('errors')->first('cloud'));

        Http::fake(['oauth2.googleapis.com/token' => $this->cloudResponse('google', 'invalid_grant', 400)]);

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'code' => 'velho', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHasErrors('cloud');

        $this->assertStringContainsString('Reconectar', session('errors')->first('cloud'));
        $this->assertSame(0, CloudConnection::withoutGlobalScopes()->count());
    }

    public function test_reconectar_atualiza_a_conexao_existente_e_preserva_o_refresh_token_quando_nao_vem_outro(): void
    {
        $existente = CloudConnection::factory()->expired()->create([
            'client_id' => $this->cliente->getKey(),
            'account_id' => '109876543210987654321',
            'refresh_token' => '1//refreshAntigoQueContinuaValendo',
        ]);
        MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'source' => MediaSource::GoogleDrive,
            'external_account_id' => $existente->getKey(),
            'external_file_id' => self::GOOGLE_FILE_ID,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => $this->cloudResponse('google', 'token_refreshed'),
            'www.googleapis.com/oauth2/v3/userinfo' => $this->cloudResponse('google', 'userinfo'),
        ]);

        $this->actingAsUser($this->gestor)
            ->withSession($this->sessao(CloudProvider::GoogleDrive))
            ->get(route('oauth.cloud.callback', ['provider' => 'google', 'code' => 'novo', 'state' => 'state-valido-1234567890']))
            ->assertRedirect(route('painel.clients.show', $this->cliente));

        $this->assertSame(1, CloudConnection::withoutGlobalScopes()->count());

        $conexao = $existente->fresh();
        $this->assertSame(ConnectionStatus::Connected, $conexao->status);
        $this->assertNull($conexao->last_error);
        $this->assertStringContainsString('tokenRenovadoDoGoogle', $conexao->access_token);
        $this->assertSame('1//refreshAntigoQueContinuaValendo', $conexao->refresh_token);
        $this->assertSame(1, $conexao->mediaAssets()->count(), 'Os assets importados continuam ligados à conexão');
    }

    public function test_desconectar_revoga_os_tokens_e_avisa_quantos_arquivos_dependem(): void
    {
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        MediaAsset::factory()->count(2)->create([
            'client_id' => $this->cliente->getKey(),
            'source' => MediaSource::GoogleDrive,
            'external_account_id' => $conexao->getKey(),
        ]);

        $this->actingAsUser($this->gestor)
            ->post(route('painel.integrations.cloud.disconnect', $conexao))
            ->assertRedirect(route('painel.clients.show', $this->cliente))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, '2 arquivo(s)'));

        $conexao->refresh();
        $this->assertSame(ConnectionStatus::Revoked, $conexao->status);
        $this->assertNull($conexao->access_token);
        $this->assertNull($conexao->refresh_token);
        $this->assertSame(2, MediaAsset::withoutGlobalScopes()->where('external_account_id', $conexao->getKey())->count());

        // Gestor de outro cliente não desconecta.
        $outro = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());
        $conexao2 = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);

        $this->actingAsUser($outro)
            ->post(route('painel.integrations.cloud.disconnect', $conexao2))
            ->assertForbidden();
    }

    public function test_token_para_o_picker_sai_so_para_quem_alcanca_o_cliente_e_renova_se_venceu(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => $this->cloudResponse('google', 'token_refreshed')]);
        $conexao = CloudConnection::factory()->expiredToken()->create(['client_id' => $this->cliente->getKey()]);

        $this->actingAsUser($this->gestor)
            ->getJson(route('painel.integrations.cloud.token', $conexao))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('api_key', 'AIzaSyChaveDaApiDaGoogle')
            ->assertJsonPath('app_id', '123456789012')
            ->assertJson(fn ($json) => $json->where('access_token', fn ($t) => str_contains((string) $t, 'tokenRenovadoDoGoogle'))->etc());

        $this->assertStringContainsString('tokenRenovadoDoGoogle', $conexao->fresh()->access_token);

        // Criador tem media.upload/view mas não integrações: pode usar o Picker.
        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);
        $this->actingAsUser($criador)->getJson(route('painel.integrations.cloud.token', $conexao))->assertOk();

        // Gestor de outro cliente: 403. Usuário do portal: 403.
        $outro = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());
        $this->actingAsUser($outro)->getJson(route('painel.integrations.cloud.token', $conexao))->assertForbidden();

        // OneDrive não usa o Picker da Google.
        $onedrive = CloudConnection::factory()->onedrive()->create(['client_id' => $this->cliente->getKey()]);
        $this->actingAsUser($this->gestor)->getJson(route('painel.integrations.cloud.token', $onedrive))->assertNotFound();
    }

    public function test_token_para_o_picker_com_refresh_revogado_marca_a_conexao_e_responde_409(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => $this->cloudResponse('google', 'invalid_grant', 400)]);
        $conexao = CloudConnection::factory()->expiredToken()->create(['client_id' => $this->cliente->getKey()]);

        $this->actingAsUser($this->gestor)
            ->getJson(route('painel.integrations.cloud.token', $conexao))
            ->assertStatus(409)
            ->assertJson(fn ($json) => $json->where('erro', fn ($e) => str_contains((string) $e, 'Reconectar'))->etc());

        $this->assertSame(ConnectionStatus::Expired, $conexao->fresh()->status);
    }

    public function test_pagina_do_cliente_mostra_conectar_e_as_conexoes_com_reconectar_quando_preciso(): void
    {
        $ok = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey(), 'account_email' => 'drive@padaria.com.br']);
        $quebrada = CloudConnection::factory()->onedrive()->expired()->create(['client_id' => $this->cliente->getKey(), 'account_email' => 'one@padaria.com.br']);

        $this->actingAsUser($this->gestor)
            ->get(route('painel.clients.show', $this->cliente))
            ->assertOk()
            ->assertSee('Arquivos na nuvem')
            ->assertSee('Conectar Google Drive')
            ->assertSee('Conectar OneDrive')
            ->assertSee('drive@padaria.com.br')
            ->assertSee('one@padaria.com.br')
            ->assertSee('Reconectar')
            ->assertSee('Desconectar')
            ->assertSee(route('painel.integrations.cloud.disconnect', $ok), false)
            ->assertSee(route('painel.integrations.cloud.connect', ['provider' => 'microsoft', 'client' => $this->cliente]), false);

        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->actingAsUser($criador)
            ->get(route('painel.clients.show', $this->cliente))
            ->assertOk()
            ->assertDontSee('Conectar Google Drive')
            ->assertDontSee('Desconectar');

        $this->assertNotNull($quebrada->last_error);
    }
}
