<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SystemHeartbeat;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O console substitui o terminal que a hospedagem não oferece — e por isso é
 * a superfície mais sensível do sistema. Estes testes guardam as duas
 * garantias: quem entra, e o que pode rodar.
 */
class MaintenanceConsoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
    }

    public function test_visitante_nao_encontra_o_console(): void
    {
        $this->get(route('maintenance.index'))->assertNotFound();
    }

    public function test_usuario_comum_nao_encontra_o_console(): void
    {
        $gestor = $this->userWithRole(RoleName::Gestor, Client::factory()->configured()->create());

        $this->actingAsUser($gestor)->get(route('maintenance.index'))->assertNotFound();
    }

    public function test_cliente_nao_encontra_o_console(): void
    {
        $aprovador = $this->userWithRole(RoleName::ClientAdmin, Client::factory()->configured()->create());

        $this->actingAsUser($aprovador)->get(route('maintenance.index'))->assertNotFound();
    }

    public function test_owner_abre_o_console(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('Console de manutenção');
    }

    public function test_token_de_emergencia_abre_o_console_quando_configurado(): void
    {
        $token = str_repeat('a', 64);
        config(['agency.maintenance_token' => $token]);

        $this->get(route('maintenance.index', ['token' => $token]))->assertOk();
        $this->get(route('maintenance.index', ['token' => str_repeat('b', 64)]))->assertNotFound();
    }

    public function test_token_vazio_nao_abre_o_console(): void
    {
        config(['agency.maintenance_token' => '']);

        $this->get(route('maintenance.index', ['token' => '']))->assertNotFound();
        $this->get(route('maintenance.index'))->assertNotFound();
    }

    public function test_token_curto_e_recusado(): void
    {
        config(['agency.maintenance_token' => 'curto-demais']);

        $this->get(route('maintenance.index', ['token' => 'curto-demais']))->assertNotFound();
    }

    public function test_comando_fora_do_catalogo_devolve_404(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->post(route('maintenance.run', 'tinker'))
            ->assertNotFound();

        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->post(route('maintenance.run', 'db:wipe'))
            ->assertNotFound();
    }

    public function test_comando_do_catalogo_roda_e_devolve_a_saida(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->post(route('maintenance.run', 'healthcheck'))
            ->assertRedirect(route('maintenance.index'))
            ->assertSessionHas('comandoExecutado');

        $this->assertNotNull(SystemHeartbeat::firstWhere('name', 'scheduler'));
    }

    public function test_porta_de_emergencia_mantem_o_token_depois_de_rodar_um_comando(): void
    {
        $token = str_repeat('a', 64);
        config(['agency.maintenance_token' => $token]);

        // Sem repassar o token no redirect, a tela de resultado devolveria 404
        // justo para quem não tem sessão autenticada.
        $this->post(route('maintenance.run', 'healthcheck'), ['token' => $token])
            ->assertRedirect(route('maintenance.index', ['token' => $token]));
    }

    public function test_owner_autenticado_nao_carrega_token_na_url(): void
    {
        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->post(route('maintenance.run', 'healthcheck'))
            ->assertRedirect(route('maintenance.index'));
    }

    public function test_formulario_repassa_o_token_para_quem_entrou_por_ele(): void
    {
        $token = str_repeat('a', 64);
        config(['agency.maintenance_token' => $token]);

        $this->get(route('maintenance.index', ['token' => $token]))
            ->assertOk()
            ->assertSee('name="token"', escape: false)
            ->assertSee($token, escape: false);
    }

    public function test_console_avisa_quando_o_agendador_parou(): void
    {
        SystemHeartbeat::create(['name' => 'scheduler', 'last_run_at' => now()->subDay()]);

        $this->actingAsUser($this->userWithRole(RoleName::Owner))
            ->get(route('maintenance.index'))
            ->assertOk()
            ->assertSee('não dá sinal desde');
    }
}
