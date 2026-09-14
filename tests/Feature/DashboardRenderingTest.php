<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Enums\RoleName;
use Database\Seeders\DemoSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fumaça: as telas da Fase 1 renderizam com os dados reais do seeder demo. */
class DashboardRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(DemoSeeder::class);
    }

    public function test_painel_da_agencia_renderiza_para_o_owner(): void
    {
        $this->actingAsUser(User::where('email', 'owner@agencia.test')->firstOrFail())
            ->get(route('painel.dashboard'))
            ->assertOk()
            ->assertSee('Travado com o cliente')
            ->assertSee('Acme Café', escape: false)
            ->assertSee('Bonsai Studio');
    }

    public function test_lista_de_clientes_mostra_apenas_os_clientes_do_gestor(): void
    {
        $this->actingAsUser(User::where('email', 'gestor@agencia.test')->firstOrFail())
            ->get(route('painel.clients.index'))
            ->assertOk()
            ->assertSee('Acme Café', escape: false)
            ->assertSee('Bonsai Studio');
    }

    public function test_criador_nao_ve_cliente_ao_qual_nao_esta_vinculado(): void
    {
        $criador = User::where('email', 'criador@agencia.test')->firstOrFail();
        $criador->clients()->detach();
        $criador->forgetAccessibleClients();

        $this->actingAsUser($criador)
            ->get(route('painel.clients.index'))
            ->assertOk()
            ->assertSee('Nenhum cliente atribuído a você')
            ->assertDontSee('Bonsai Studio');
    }

    public function test_portal_do_cliente_mostra_somente_o_proprio_perfil(): void
    {
        $this->actingAsUser(User::where('email', 'aprovador@acme.test')->firstOrFail())
            ->get(route('portal.dashboard'))
            ->assertOk()
            ->assertSee('Acme Café', escape: false)
            ->assertDontSee('Bonsai Studio');
    }

    public function test_portal_nao_expoe_nada_tecnico_ao_cliente(): void
    {
        $response = $this->actingAsUser(User::where('email', 'leitor@bonsai.test')->firstOrFail())
            ->get(route('portal.dashboard'))
            ->assertOk();

        // Seção 6.10: o menu do portal tem no máximo estes cinco itens, e
        // nenhuma tela técnica (integrações, contas, saúde do sistema).
        foreach (['Calendário', 'Aprovações', 'Arquivos', 'Relatórios', 'Solicitações'] as $item) {
            $response->assertSee($item, escape: false);
        }

        foreach (['Integrações', 'Configurações', 'Contas conectadas', 'Saúde do sistema', 'Clientes'] as $proibido) {
            $response->assertDontSee($proibido, escape: false);
        }
    }

    public function test_client_viewer_nao_pode_decidir_aprovacao(): void
    {
        $leitor = User::where('email', 'leitor@bonsai.test')->firstOrFail();
        $post = $leitor->clients()->first()->posts()->firstOrFail();

        $this->assertTrue($leitor->can('view', $post));
        $this->assertFalse($leitor->can('decide', $post));
    }

    public function test_criador_nao_publica_nem_aprova(): void
    {
        $criador = User::where('email', 'criador@agencia.test')->firstOrFail();
        $post = $criador->clients()->first()->posts()->firstOrFail();

        $this->assertTrue($criador->can('update', $post));
        $this->assertFalse($criador->can('publish', $post));
        $this->assertFalse($criador->can('decide', $post));
    }

    public function test_apenas_o_owner_exclui_cliente(): void
    {
        $owner = User::where('email', 'owner@agencia.test')->firstOrFail();
        $gestor = User::where('email', 'gestor@agencia.test')->firstOrFail();
        $client = $gestor->clients()->firstOrFail();

        $this->assertTrue($owner->can('delete', $client));
        $this->assertFalse($gestor->can('delete', $client));
        $this->assertSame(RoleName::Gestor, $gestor->primaryRole());
    }
}
