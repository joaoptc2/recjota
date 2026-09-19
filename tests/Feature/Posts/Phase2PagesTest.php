<?php

declare(strict_types=1);

namespace Tests\Feature\Posts;

use App\Livewire\Tasks\TaskBoard;
use App\Models\Client;
use App\Models\Post;
use App\Models\Task;
use App\Support\Enums\RoleName;
use App\Support\Enums\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** As telas da Fase 2 abrem, e cada papel vê o que lhe cabe. */
class Phase2PagesTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create();
        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));
    }

    public function test_paginas_da_fase_2_renderizam(): void
    {
        $this->get(route('painel.calendar'))->assertOk()->assertSee('Calendário');
        $this->get(route('painel.media'))->assertOk()->assertSee('Biblioteca');
        $this->get(route('painel.tasks'))->assertOk()->assertSee('Tarefas');
        $this->get(route('painel.posts.create'))->assertOk()->assertSee('Como vai aparecer');
    }

    public function test_menu_nao_marca_mais_calendario_biblioteca_e_tarefas_como_em_breve(): void
    {
        $resposta = $this->get(route('painel.dashboard'))->assertOk();

        foreach (['painel.calendar', 'painel.media', 'painel.tasks', 'painel.approvals'] as $rota) {
            $resposta->assertSee('href="'.route($rota).'"', escape: false);
        }

        // Sobram desativados apenas os de fases futuras: Relatórios (Fase 6) e
        // Configurações (Fase 7).
        $this->assertSame(2, substr_count($resposta->getContent(), 'em breve'));
    }

    public function test_criador_nao_pode_abrir_o_editor_de_post_alheio(): void
    {
        $outro = Client::factory()->configured()->create();
        $post = Post::factory()->create(['client_id' => $outro->getKey()]);

        $criador = $this->userWithRole(RoleName::Criador, $this->cliente);

        $this->actingAsUser($criador)
            ->get(route('painel.posts.edit', $post))
            ->assertForbidden();
    }

    public function test_quadro_de_tarefas_move_entre_colunas(): void
    {
        $tarefa = Task::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'status' => TaskStatus::Todo,
        ]);

        Livewire::test(TaskBoard::class, ['client' => $this->cliente])
            ->call('move', $tarefa->getKey(), TaskStatus::Doing->value);

        $this->assertSame(TaskStatus::Doing, $tarefa->fresh()->status);
    }

    public function test_tarefa_concluida_recebe_data_de_conclusao(): void
    {
        $tarefa = Task::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'status' => TaskStatus::Doing,
        ]);

        Livewire::test(TaskBoard::class, ['client' => $this->cliente])
            ->call('move', $tarefa->getKey(), TaskStatus::Done->value);

        $this->assertNotNull($tarefa->fresh()->completed_at);
    }

    public function test_filtro_minhas_tarefas_esconde_as_dos_outros(): void
    {
        $eu = auth()->user();
        $colega = $this->userWithRole(RoleName::Criador, $this->cliente);

        Task::factory()->create(['client_id' => $this->cliente->getKey(), 'assignee_id' => $eu->getKey()]);
        Task::factory()->count(2)->create(['client_id' => $this->cliente->getKey(), 'assignee_id' => $colega->getKey()]);

        $this->actingAsUser($eu);

        $componente = Livewire::test(TaskBoard::class, ['client' => $this->cliente]);
        $total = collect($componente->instance()->columns())->sum(fn ($c) => $c['tarefas']->count());
        $this->assertSame(3, $total);

        $componente->set('mine', true);
        $meu = collect($componente->instance()->columns())->sum(fn ($c) => $c['tarefas']->count());
        $this->assertSame(1, $meu);
    }

    public function test_quadro_de_tarefas_nao_vaza_entre_clientes(): void
    {
        $alheio = Client::factory()->configured()->create();
        Task::factory()->count(4)->create(['client_id' => $alheio->getKey()]);

        $componente = Livewire::test(TaskBoard::class);
        $total = collect($componente->instance()->columns())->sum(fn ($c) => $c['tarefas']->count());

        $this->assertSame(0, $total);
    }
}
