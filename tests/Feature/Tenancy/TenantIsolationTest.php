<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Client;
use App\Models\Post;
use App\Models\Task;
use App\Support\Enums\RoleName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Teste de isolamento multi-tenant (Seção 13). É o critério de aceite da Fase 1
 * e roda em CI: usuário do cliente A não alcança recurso do cliente B, nem
 * pela listagem, nem por ID direto na URL.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Client $clienteA;

    private Client $clienteB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();

        $this->clienteA = Client::factory()->configured()->create(['name' => 'Cliente A']);
        $this->clienteB = Client::factory()->configured()->create(['name' => 'Cliente B']);
    }

    public function test_usuario_do_cliente_a_recebe_403_ao_abrir_post_do_cliente_b_por_id_direto(): void
    {
        $aprovadorA = $this->userWithRole(RoleName::ClientAdmin, $this->clienteA);
        $postDoB = Post::factory()->create(['client_id' => $this->clienteB->getKey()]);

        $this->actingAsUser($aprovadorA)
            ->get(route('portal.posts.show', $postDoB))
            ->assertForbidden();
    }

    public function test_usuario_do_cliente_a_abre_normalmente_o_proprio_post(): void
    {
        $aprovadorA = $this->userWithRole(RoleName::ClientAdmin, $this->clienteA);
        $postDoA = Post::factory()->create(['client_id' => $this->clienteA->getKey()]);

        $this->actingAsUser($aprovadorA)
            ->get(route('portal.posts.show', $postDoA))
            ->assertOk();
    }

    public function test_gestor_sem_vinculo_recebe_403_no_cliente_alheio(): void
    {
        $gestor = $this->userWithRole(RoleName::Gestor, $this->clienteA);

        $this->actingAsUser($gestor)
            ->get(route('painel.clients.show', $this->clienteB))
            ->assertForbidden();

        $this->actingAsUser($gestor)
            ->get(route('painel.clients.show', $this->clienteA))
            ->assertOk();
    }

    public function test_owner_alcanca_todos_os_clientes_sem_vinculo_explicito(): void
    {
        $owner = $this->userWithRole(RoleName::Owner);

        $this->actingAsUser($owner)
            ->get(route('painel.clients.show', $this->clienteB))
            ->assertOk();
    }

    public function test_global_scope_esconde_registros_de_outro_cliente_nas_consultas(): void
    {
        Post::factory()->count(3)->create(['client_id' => $this->clienteA->getKey()]);
        Post::factory()->count(5)->create(['client_id' => $this->clienteB->getKey()]);
        Task::factory()->count(2)->create(['client_id' => $this->clienteB->getKey()]);

        $criadorA = $this->userWithRole(RoleName::Criador, $this->clienteA);
        $this->actingAsUser($criadorA);

        $this->assertSame(3, Post::query()->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame([$this->clienteA->getKey()], Client::query()->pluck('id')->all());
    }

    public function test_usuario_sem_cliente_vinculado_nao_enxerga_nada(): void
    {
        Post::factory()->count(4)->create(['client_id' => $this->clienteA->getKey()]);

        $criadorSemVinculo = $this->userWithRole(RoleName::Criador);
        $this->actingAsUser($criadorSemVinculo);

        $this->assertSame(0, Post::query()->count());
        $this->assertSame(0, Client::query()->count());
    }

    public function test_portal_do_cliente_nao_acessa_o_painel_da_agencia(): void
    {
        $aprovadorA = $this->userWithRole(RoleName::ClientAdmin, $this->clienteA);

        $this->actingAsUser($aprovadorA)
            ->get(route('painel.clients.index'))
            ->assertRedirect(route('portal.dashboard'));
    }

    public function test_usuario_da_agencia_nao_cai_no_portal_do_cliente(): void
    {
        $gestor = $this->userWithRole(RoleName::Gestor, $this->clienteA);

        $this->actingAsUser($gestor)
            ->get(route('portal.dashboard'))
            ->assertRedirect(route('painel.dashboard'));
    }

    public function test_escopo_pode_ser_suspenso_explicitamente_para_jobs_e_console(): void
    {
        Post::factory()->count(2)->create(['client_id' => $this->clienteB->getKey()]);

        $criadorA = $this->userWithRole(RoleName::Criador, $this->clienteA);
        $this->actingAsUser($criadorA);

        $this->assertSame(0, Post::query()->count());

        $total = app(TenantContext::class)->withoutRestriction(fn () => Post::query()->count());

        $this->assertSame(2, $total);
    }
}
