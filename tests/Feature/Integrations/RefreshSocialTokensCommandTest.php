<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\SocialAccountExpired;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\RoleName;
use App\Support\Enums\SocialPlatform;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Renovação automática de tokens (Seção 7.1.3). Renova só o que está para
 * vencer, respeita a idade mínima de 24h e transforma erro permanente em
 * alerta para quem pode reconectar.
 */
class RefreshSocialTokensCommandTest extends TestCase
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

        $this->cliente = Client::factory()->configured()->create();
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
    }

    public function test_renova_apenas_contas_elegiveis(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('refresh_access_token')]);

        $elegivel = SocialAccount::factory()->expiringSoon()->create([
            'client_id' => $this->cliente->getKey(),
            'access_token' => 'IGAAtokenAntigoQueVaiSerRenovado0000000000',
        ]);
        $longe = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey(), 'token_expires_at' => now()->addDays(30)]);
        $novaDemais = SocialAccount::factory()->expiringSoon()->freshlyRefreshed()->create(['client_id' => $this->cliente->getKey()]);
        $jaExpirada = SocialAccount::factory()->expired()->create(['client_id' => $this->cliente->getKey()]);
        $outraRede = SocialAccount::factory()->expiringSoon()->create([
            'client_id' => $this->cliente->getKey(),
            'platform' => SocialPlatform::Facebook,
        ]);

        $this->artisan('tokens:refresh')
            ->expectsOutputToContain('1 renovado(s), 0 expirado(s), 0 adiado(s).')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'ig_refresh_token'
            && $r['access_token'] === 'IGAAtokenAntigoQueVaiSerRenovado0000000000');

        $renovada = $elegivel->fresh();
        $this->assertSame($this->refreshedToken(), $renovada->access_token);
        $this->assertDatabaseMissing('social_accounts', ['access_token' => $this->refreshedToken()]);
        $this->assertEqualsWithDelta(60, $renovada->daysUntilTokenExpires(), 1);
        $this->assertTrue($renovada->token_refreshed_at->greaterThan(now()->subMinute()));
        $this->assertSame(ConnectionStatus::Connected, $renovada->connection_status);

        foreach ([$longe, $novaDemais, $jaExpirada, $outraRede] as $intocada) {
            $this->assertSame($intocada->access_token, $intocada->fresh()->access_token);
            $this->assertEquals($intocada->token_expires_at, $intocada->fresh()->token_expires_at);
        }

        Notification::assertNothingSent();
    }

    public function test_conta_antiga_sem_token_refreshed_at_usa_updated_at_como_idade(): void
    {
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('refresh_access_token')]);

        $legado = SocialAccount::factory()->expiringSoon()->create([
            'client_id' => $this->cliente->getKey(),
            'token_refreshed_at' => null,
        ]);
        SocialAccount::withoutGlobalScopes()->whereKey($legado->getKey())->update(['updated_at' => now()->subDays(3)]);

        $recente = SocialAccount::factory()->expiringSoon()->create([
            'client_id' => $this->cliente->getKey(),
            'token_refreshed_at' => null,
        ]);

        $this->artisan('tokens:refresh')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame($this->refreshedToken(), $legado->fresh()->access_token);
        $this->assertSame($recente->access_token, $recente->fresh()->access_token);
    }

    public function test_erro_190_marca_expirada_grava_o_motivo_e_avisa_os_gestores(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('error_190', 400)]);

        $conta = SocialAccount::factory()->expiringSoon()->create(['client_id' => $this->cliente->getKey()]);
        $tokenAntigo = $conta->access_token;

        $outroGestor = $this->userWithRole(RoleName::Gestor, Client::factory()->create());
        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->artisan('tokens:refresh')
            ->expectsOutputToContain('0 renovado(s), 1 expirado(s), 0 adiado(s).')
            ->assertSuccessful();

        $conta->refresh();
        $this->assertSame(ConnectionStatus::Expired, $conta->connection_status);
        $this->assertStringContainsString('Reconectar', (string) $conta->last_error);
        $this->assertSame($tokenAntigo, $conta->access_token);

        Notification::assertSentTo($this->gestor, SocialAccountExpired::class, function (SocialAccountExpired $n, array $canais) use ($conta): bool {
            return $n->account->is($conta)
                && in_array('mail', $canais, true)
                && in_array('database', $canais, true);
        });
        Notification::assertNotSentTo($outroGestor, SocialAccountExpired::class);
        Notification::assertNotSentTo($criador, SocialAccountExpired::class);
    }

    public function test_sem_gestor_no_cliente_o_aviso_vai_para_owner_ou_admin(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('error_190', 400)]);

        $semGestor = Client::factory()->configured()->create();
        $owner = $this->userWithRole(RoleName::Owner);
        SocialAccount::factory()->expiringSoon()->create(['client_id' => $semGestor->getKey()]);

        $this->artisan('tokens:refresh')->assertSuccessful();

        Notification::assertSentTo($owner, SocialAccountExpired::class);
        Notification::assertNotSentTo($this->gestor, SocialAccountExpired::class);
    }

    public function test_cliente_arquivado_nao_quebra_o_comando_e_o_aviso_vai_para_owner(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('error_190', 400)]);

        $arquivado = Client::factory()->configured()->create();
        $this->userWithRole(RoleName::Gestor, $arquivado);
        $owner = $this->userWithRole(RoleName::Owner);
        $conta = SocialAccount::factory()->expiringSoon()->create(['client_id' => $arquivado->getKey()]);
        $arquivado->delete();

        $this->artisan('tokens:refresh')->assertSuccessful();

        $this->assertSame(ConnectionStatus::Expired, $conta->fresh()->connection_status);
        Notification::assertSentTo($owner, SocialAccountExpired::class);
    }

    public function test_erro_transitorio_adia_sem_mudar_status_nem_avisar(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => $this->fixtureResponse('error_4', 400)]);

        $conta = SocialAccount::factory()->expiringSoon()->create(['client_id' => $this->cliente->getKey()]);

        $this->artisan('tokens:refresh')
            ->expectsOutputToContain('0 renovado(s), 0 expirado(s), 1 adiado(s).')
            ->assertSuccessful();

        $this->assertSame(ConnectionStatus::Connected, $conta->fresh()->connection_status);
        Notification::assertNothingSent();
    }

    public function test_instabilidade_5xx_tambem_e_adiada(): void
    {
        Notification::fake();
        Http::fake(['graph.instagram.com/v23.0/refresh_access_token*' => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503)]);

        $conta = SocialAccount::factory()->expiringSoon()->create(['client_id' => $this->cliente->getKey()]);

        $this->artisan('tokens:refresh')->assertSuccessful();

        $this->assertSame(ConnectionStatus::Connected, $conta->fresh()->connection_status);
        Notification::assertNothingSent();
    }

    public function test_o_email_de_conta_expirada_renderiza_com_link_para_reconectar(): void
    {
        $conta = SocialAccount::factory()->expired()->create(['client_id' => $this->cliente->getKey()]);

        $html = (new SocialAccountExpired($conta, 'O Instagram não reconhece mais o acesso.'))
            ->toMail($this->gestor)
            ->render();

        $this->assertStringContainsString($this->cliente->name, (string) $html);
        $this->assertStringContainsString('Reconectar agora', (string) $html);
        $this->assertStringContainsString(route('painel.clients.show', $this->cliente), (string) $html);
        $this->assertStringNotContainsString($conta->access_token, (string) $html);
    }

    public function test_agendamento_diario_esta_registrado_sem_schedule_command(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        $renovacao = $eventos->first(fn ($e) => $e->description === 'renovar-tokens');

        $this->assertNotNull($renovacao, 'Agendamento renovar-tokens ausente em routes/console.php');
        $this->assertSame('0 3 * * *', $renovacao->expression);
        $this->assertInstanceOf(CallbackEvent::class, $renovacao);
        $this->assertTrue($renovacao->withoutOverlapping);
    }
}
