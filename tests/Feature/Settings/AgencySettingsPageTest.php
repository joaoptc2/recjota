<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Actions\System\RetryFailedJob;
use App\Actions\Users\InviteUser;
use App\Actions\Users\ToggleUserActive;
use App\Livewire\Settings\AgencySettings;
use App\Models\Client;
use App\Models\Invitation;
use App\Models\Setting;
use App\Models\SocialAccount;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Notifications\UserInvited;
use App\Support\DataObjects\InvitationData;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use App\Support\Settings;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/** Configurações da agência (Seção 11.2): owner/admin, nunca o portal. */
class AgencySettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->owner = $this->userWithRole(RoleName::Owner);
    }

    public function test_owner_e_admin_abrem_a_pagina_com_todas_as_secoes(): void
    {
        SystemHeartbeat::factory()->create();

        $this->actingAsUser($this->owner);

        $this->get(route('painel.settings'))
            ->assertOk()
            ->assertSee('Configurações')
            ->assertSee('Saúde do sistema')
            ->assertSee('Dados e branding da agência')
            ->assertSee('Usuários da agência')
            ->assertSee('Convites pendentes')
            ->assertSee('Nenhum job falhado')
            ->assertDontSee('O cron não bate');

        $this->actingAsUser($this->userWithRole(RoleName::Admin))
            ->get(route('painel.settings'))
            ->assertOk();
    }

    public function test_criador_gestor_e_cliente_recebem_403(): void
    {
        $cliente = Client::factory()->configured()->create();

        foreach ([RoleName::Criador, RoleName::Gestor, RoleName::ClientAdmin] as $papel) {
            $this->actingAsUser($this->userWithRole($papel, $cliente))
                ->get(route('painel.settings'))
                ->assertForbidden();
        }
    }

    public function test_cron_parado_aparece_com_alerta_e_instrucao(): void
    {
        SystemHeartbeat::factory()->stale(40)->create();

        $this->actingAsUser($this->owner);

        $this->get(route('painel.settings'))
            ->assertOk()
            ->assertSee('O cron não bate há mais de 20 minutos')
            ->assertSee('cron.sh');
    }

    public function test_branding_e_salvo_na_tabela_settings_e_aplicado_no_layout(): void
    {
        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->set('agencyName', 'Agência Nova')
            ->set('supportEmail', 'Suporte@Nova.test')
            ->set('primaryColor', '#ab12cd')
            ->call('saveBranding')
            ->assertHasNoErrors()
            ->assertSet('feedback', fn ($f) => str_contains((string) $f, 'salvos'));

        $this->assertDatabaseHas('settings', ['key' => 'agency.name', 'client_id' => null]);
        $this->assertSame('Agência Nova', Setting::firstWhere('key', 'agency.name')->value);
        $this->assertSame('suporte@nova.test', app(Settings::class)->get('agency.support_email'));
        $this->assertSame('#AB12CD', app(Settings::class)->get('agency.primary_color'));

        // Na próxima requisição o middleware aplica sobre config('agency.*').
        $this->get(route('painel.dashboard'))->assertOk()->assertSee('Agência Nova');
    }

    public function test_branding_invalido_nao_e_salvo(): void
    {
        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->set('agencyName', '')
            ->set('primaryColor', 'roxo')
            ->call('saveBranding')
            ->assertHasErrors(['agencyName', 'primaryColor']);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_settings_cai_no_padrao_do_env_quando_nao_ha_linha(): void
    {
        config(['agency.name' => 'Padrão do Env']);

        $this->assertSame('Padrão do Env', app(Settings::class)->get('agency.name'));
    }

    public function test_owner_desativa_e_reativa_usuario_da_agencia_mas_nao_a_si_mesmo(): void
    {
        $gestor = $this->userWithRole(RoleName::Gestor, Client::factory()->create());

        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->call('toggleUser', $gestor->getKey())
            ->assertHasNoErrors();

        $this->assertFalse($gestor->fresh()->is_active);

        Livewire::test(AgencySettings::class)
            ->call('toggleUser', $gestor->getKey());

        $this->assertTrue($gestor->fresh()->is_active);

        Livewire::test(AgencySettings::class)
            ->call('toggleUser', $this->owner->getKey())
            ->assertHasErrors('users');

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_admin_nao_desativa_owner_e_o_ultimo_owner_ativo_nunca_e_desativado(): void
    {
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAsUser($admin);

        Livewire::test(AgencySettings::class)
            ->call('toggleUser', $this->owner->getKey())
            ->assertHasErrors('users');

        $this->assertTrue($this->owner->fresh()->is_active);

        // Dois owners: um pode desativar o outro; o que sobra não pode ser desativado.
        $segundoOwner = $this->userWithRole(RoleName::Owner);

        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->call('toggleUser', $segundoOwner->getKey())
            ->assertHasNoErrors();

        $this->assertFalse($segundoOwner->fresh()->is_active);

        // Sobrou um owner ativo: nem outro owner (já inativo) consegue derrubá-lo.
        try {
            app(ToggleUserActive::class)($this->owner->fresh(), $segundoOwner->fresh());
            $this->fail('Deveria recusar desativar o último owner ativo');
        } catch (DomainException $e) {
            $this->assertStringContainsString('único proprietário ativo', $e->getMessage());
        }

        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_convite_cria_invitation_com_hash_e_notifica_o_email(): void
    {
        Notification::fake();

        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->set('inviteName', 'Nova Gestora')
            ->set('inviteEmail', 'Nova@Agencia.test')
            ->set('inviteRole', RoleName::Gestor->value)
            ->call('invite')
            ->assertHasNoErrors()
            ->assertSet('inviteEmail', '')
            ->assertSee('nova@agencia.test');

        $convite = Invitation::firstWhere('email', 'nova@agencia.test');

        $this->assertNotNull($convite);
        $this->assertSame(RoleName::Gestor, $convite->role);
        $this->assertSame(UserType::Agency, $convite->type);
        $this->assertSame($this->owner->getKey(), $convite->invited_by);
        $this->assertSame(64, strlen($convite->token));
        $this->assertTrue($convite->expires_at->between(now()->addDays(6), now()->addDays(8)));

        Notification::assertSentOnDemand(UserInvited::class, function (UserInvited $n, array $canais, AnonymousNotifiable $para) use ($convite): bool {
            $token = Str::afterLast($n->acceptUrl(), '/');

            return $para->routes['mail'] === 'nova@agencia.test'
                && $n->invitation->is($convite)
                && Invitation::hashToken($token) === $convite->token
                && $n->acceptUrl() === route('invitation.show', $token);
        });
    }

    public function test_link_do_convite_abre_a_tela_de_aceite(): void
    {
        Notification::fake();

        $convite = app(InviteUser::class)(new InvitationData(email: 'aceite@agencia.test', role: RoleName::Criador));

        $token = null;
        Notification::assertSentOnDemand(UserInvited::class, function (UserInvited $n) use (&$token): bool {
            $token = Str::afterLast($n->acceptUrl(), '/');

            return true;
        });

        $this->get(route('invitation.show', $token))->assertOk();
        $this->assertStringContainsString('Aceitar o convite', (string) (new UserInvited($convite, $token))->toMail(new AnonymousNotifiable)->render());
    }

    public function test_convite_para_email_de_usuario_existente_e_recusado(): void
    {
        Notification::fake();

        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->set('inviteEmail', $this->owner->email)
            ->set('inviteRole', RoleName::Criador->value)
            ->call('invite')
            ->assertHasErrors('inviteEmail');

        $this->assertDatabaseCount('invitations', 0);
        Notification::assertNothingSent();
    }

    public function test_novo_convite_revoga_o_pendente_anterior_do_mesmo_email(): void
    {
        Notification::fake();

        $antigo = Invitation::factory()->create(['email' => 'repetido@agencia.test']);

        app(InviteUser::class)(new InvitationData(email: 'repetido@agencia.test', role: RoleName::Criador));

        $this->assertNotNull($antigo->fresh()->revoked_at);
        $this->assertSame(1, Invitation::where('email', 'repetido@agencia.test')->whereNull('revoked_at')->count());
    }

    public function test_admin_nao_convida_owner_mas_owner_convida(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Admin));
        $this->assertArrayNotHasKey('owner', Livewire::test(AgencySettings::class)->instance()->invitableRoles());

        $this->actingAsUser($this->owner);
        $this->assertArrayHasKey('owner', Livewire::test(AgencySettings::class)->instance()->invitableRoles());
    }

    public function test_convite_pendente_pode_ser_cancelado(): void
    {
        $convite = Invitation::factory()->create();

        $this->actingAsUser($this->owner);

        $componente = Livewire::test(AgencySettings::class)
            ->assertSee($convite->email)
            ->call('revokeInvitation', $convite->getKey())
            ->assertSet('feedback', fn ($f) => str_contains((string) $f, 'cancelado'));

        $this->assertFalse($convite->fresh()->isUsable());
        $this->assertTrue($componente->instance()->pendingInvitations()->isEmpty());
    }

    public function test_saude_lista_tokens_vencendo_e_tamanho_da_fila(): void
    {
        $cliente = Client::factory()->configured()->create();
        SocialAccount::factory()->create(['client_id' => $cliente->getKey(), 'username' => 'vence_logo', 'token_expires_at' => now()->addDays(2)]);
        SocialAccount::factory()->create(['client_id' => $cliente->getKey(), 'username' => 'tranquila', 'token_expires_at' => now()->addDays(30)]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);

        $this->actingAsUser($this->owner);

        $componente = Livewire::test(AgencySettings::class)
            ->assertSee('@vence_logo')
            ->assertDontSee('@tranquila');

        $this->assertSame(1, $componente->instance()->queueSize());
    }

    public function test_tentar_de_novo_reenfileira_o_job_falhado(): void
    {
        $uuid = $this->insertFailedJob();

        $this->actingAsUser($this->owner);

        Livewire::test(AgencySettings::class)
            ->assertSee('CreateContainerJob')
            ->assertSee('RuntimeException: Boom')
            ->call('retryFailedJob', $uuid)
            ->assertSet('saidaRetry', fn ($s) => str_contains((string) $s, $uuid))
            ->assertSee('Nenhum job falhado');

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => $uuid]);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_tentar_de_novo_chama_o_comando_queue_retry(): void
    {
        $uuid = $this->insertFailedJob();

        Artisan::shouldReceive('call')->once()->with('queue:retry', ['id' => [$uuid]])->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn("The failed job [{$uuid}] has been pushed back onto the queue!");

        $this->assertStringContainsString($uuid, app(RetryFailedJob::class)($uuid));
    }

    public function test_portal_do_cliente_nao_tem_rota_de_configuracoes(): void
    {
        $this->assertFalse(app('router')->has('portal.settings'));
        $this->assertFalse(app('router')->has('portal.integrations'));
    }

    private function insertFailedJob(): string
    {
        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\Publishing\\CreateContainerJob', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 1, 'maxTries' => 1, 'data' => ['commandName' => 'App\\Jobs\\Publishing\\CreateContainerJob']]),
            'exception' => "RuntimeException: Boom\n#0 ...",
            'failed_at' => now()->subMinutes(3),
        ]);

        return $uuid;
    }
}
