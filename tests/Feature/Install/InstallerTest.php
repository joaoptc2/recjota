<?php

declare(strict_types=1);

namespace Tests\Feature\Install;

use App\Models\User;
use App\Support\Enums\RoleName;
use App\Support\Installation;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Instalador web para hospedagem sem SSH. O risco aqui é ele virar uma porta
 * dos fundos: estes testes existem para garantir que ele some após o uso.
 */
class InstallerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Aqui queremos o comportamento real: sem lock e sem usuários, o
        // instalador precisa aparecer.
        File::delete(Installation::lockPath());
        Installation::forgetCache();
    }

    protected function tearDown(): void
    {
        File::delete(Installation::lockPath());
        Installation::forgetCache();

        parent::tearDown();
    }

    public function test_instalador_abre_quando_nao_ha_usuarios_nem_lock(): void
    {
        $this->get(route('install.requirements'))
            ->assertOk()
            ->assertSee('Instalação do Recjota');
    }

    public function test_instalador_some_quando_ja_existe_usuario(): void
    {
        User::factory()->create();

        $this->get(route('install.requirements'))->assertNotFound();
        $this->get(route('install.environment'))->assertNotFound();
        $this->post(route('install.database.run'))->assertNotFound();
        $this->post(route('install.administrator.store'))->assertNotFound();
    }

    public function test_instalador_some_quando_o_lock_existe(): void
    {
        Installation::markInstalled();

        $this->get(route('install.requirements'))->assertNotFound();
    }

    public function test_apagar_o_lock_nao_reabre_o_instalador_se_houver_usuarios(): void
    {
        User::factory()->create();

        File::delete(Installation::lockPath());
        Installation::forgetCache();

        $this->get(route('install.requirements'))->assertNotFound();
    }

    public function test_criacao_do_administrador_gera_owner_e_fecha_o_instalador(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->post(route('install.administrator.store'), [
            'name' => 'Ana Proprietária',
            'email' => 'ana@agencia.test',
            'password' => 'uma-senha-bem-longa',
            'password_confirmation' => 'uma-senha-bem-longa',
            // O lock devolve a sessão para o driver `database`, e a sessão em
            // arquivo desta requisição não sobrevive ao redirect. Por isso a
            // instalação termina na tela de entrada, não no painel.
        ])->assertRedirect(route('login'));

        $owner = User::where('email', 'ana@agencia.test')->firstOrFail();

        $this->assertTrue($owner->hasRole(RoleName::Owner->value));
        $this->assertTrue($owner->isAgency());
        $this->assertTrue($owner->seesEveryClient());

        $this->assertFileExists(Installation::lockPath());

        $this->get(route('install.requirements'))->assertNotFound();
    }

    public function test_senha_curta_do_proprietario_e_recusada(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->post(route('install.administrator.store'), [
            'name' => 'Ana',
            'email' => 'ana@agencia.test',
            'password' => 'curta',
            'password_confirmation' => 'curta',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'ana@agencia.test']);
    }

    public function test_visitante_sem_instalacao_e_levado_ao_instalador(): void
    {
        $this->get('/')->assertRedirect(route('install.requirements'));
        $this->get(route('login'))->assertRedirect(route('install.requirements'));
    }

    public function test_health_continua_respondendo_durante_a_instalacao(): void
    {
        $this->get(route('health'))
            ->assertStatus(503)
            ->assertJsonPath('checks.database.ok', true);
    }

    public function test_tela_de_ambiente_recusa_credenciais_de_banco_invalidas(): void
    {
        $this->post(route('install.environment.store'), [
            'app_name' => 'Agência Teste',
            'app_url' => 'https://exemplo.test',
            'timezone' => 'America/Sao_Paulo',
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_database' => 'banco_que_nao_existe',
            'db_username' => 'usuario_invalido',
            'db_password' => 'senha_invalida',
        ])->assertSessionHasErrors('db_database');
    }

    public function test_marcar_dados_demo_nao_mata_o_instalador_no_passo_seguinte(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        // O DemoSeeder cria usuários; se rodasse aqui, o portão fecharia e o
        // passo do administrador devolveria 404.
        $this->post(route('install.database.run'), ['demo' => true])
            ->assertRedirect(route('install.administrator'));

        $this->assertSame(0, User::count(), 'Nenhum usuário pode existir antes do proprietário.');

        $this->get(route('install.administrator'))->assertOk();

        $this->post(route('install.administrator.store'), [
            'name' => 'Ana Proprietária',
            'email' => 'ana@agencia.test',
            'password' => 'uma-senha-bem-longa',
            'password_confirmation' => 'uma-senha-bem-longa',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', ['email' => 'ana@agencia.test']);
        // A escolha da demo é aplicada depois do lock, não antes.
        $this->assertDatabaseHas('clients', ['name' => 'Acme Café']);
        $this->assertFileExists(Installation::lockPath());
    }

    public function test_tela_de_ambiente_valida_o_endereco_do_sistema(): void
    {
        $this->post(route('install.environment.store'), [
            'app_name' => 'Agência Teste',
            'app_url' => 'nao-e-uma-url',
            'timezone' => 'America/Sao_Paulo',
            'db_host' => 'localhost',
            'db_port' => 3306,
            'db_database' => 'x',
            'db_username' => 'y',
        ])->assertSessionHasErrors('app_url');
    }
}
