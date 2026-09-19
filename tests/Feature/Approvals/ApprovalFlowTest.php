<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Actions\Approvals\SendApprovalRequest;
use App\Livewire\Approvals\ApprovalQueue;
use App\Models\Approval;
use App\Models\Client;
use App\Models\Comment;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\User;
use App\Notifications\ApprovalDeadlineApproaching;
use App\Notifications\ApprovalDecided;
use App\Notifications\PostAwaitingApproval;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    private User $gestor;

    private User $aprovador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured(['approval_deadline_hours' => 24])->create();
        $this->gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
        $this->aprovador = $this->userWithRole(RoleName::ClientAdmin, $this->cliente);
    }

    private function postPronto(): Post
    {
        $post = Post::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'caption' => 'Post para decidir',
        ]);

        $post->media()->attach(
            MediaAsset::factory()->create(['client_id' => $this->cliente->getKey()])->getKey(),
            ['position' => 0],
        );

        return $post->refresh();
    }

    public function test_enviar_para_aprovacao_notifica_os_aprovadores_com_link_proprio(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);

        $links = app(SendApprovalRequest::class)($this->postPronto(), $this->gestor->getKey());

        Notification::assertSentTo($this->aprovador, PostAwaitingApproval::class);
        // A equipe não recebe o e-mail de "aprove isto".
        Notification::assertNotSentTo($this->gestor, PostAwaitingApproval::class);

        $this->assertCount(1, $links);
        $this->assertSame($this->aprovador->email, $links[0]['email']);
        $this->assertStringContainsString('/aprovar/', $links[0]['url']);
    }

    public function test_cliente_sem_aprovador_ainda_recebe_um_link_para_envio_manual(): void
    {
        Notification::fake();
        $semAprovador = Client::factory()->configured()->create();
        $gestor = $this->userWithRole(RoleName::Gestor, $semAprovador);
        $this->actingAsUser($gestor);

        $post = Post::factory()->create(['client_id' => $semAprovador->getKey()]);

        $links = app(SendApprovalRequest::class)($post, $gestor->getKey());

        $this->assertCount(1, $links);
        $this->assertNull($links[0]['email']);
        $this->assertStringContainsString('/aprovar/', $links[0]['url']);
    }

    public function test_decisao_avisa_a_equipe_da_agencia(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();
        app(SendApprovalRequest::class)($post, $this->gestor->getKey());

        $this->actingAsUser($this->aprovador);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('decide', $post->getKey(), 'approved');

        Notification::assertSentTo($this->gestor, ApprovalDecided::class);
        $this->assertSame(PostStatus::Approved, $post->refresh()->status);
    }

    public function test_portal_aprova_em_lote(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);

        $posts = collect(range(1, 3))->map(fn () => $this->postPronto());

        foreach ($posts as $post) {
            app(SendApprovalRequest::class)($post, $this->gestor->getKey());
        }

        $this->actingAsUser($this->aprovador);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('approveAll');

        foreach ($posts as $post) {
            $this->assertSame(PostStatus::Approved, $post->refresh()->status);
            $this->assertSame($post->current_version, $post->approved_version);
        }
    }

    public function test_client_viewer_nao_decide(): void
    {
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();
        app(SendApprovalRequest::class)($post, $this->gestor->getKey());

        $leitor = $this->userWithRole(RoleName::ClientViewer, $this->cliente);
        $this->actingAsUser($leitor);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('decide', $post->getKey(), 'approved')
            ->assertForbidden();

        $this->assertSame(PostStatus::AwaitingClient, $post->refresh()->status);
    }

    public function test_comentario_interno_nao_aparece_para_o_cliente(): void
    {
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente])
            ->call('focus', $post->getKey())
            ->set('comentario', 'SEGREDO: cliente é difícil')
            ->set('comentarioInterno', true)
            ->call('comment', $post->getKey());

        $this->assertTrue(Comment::firstOrFail()->is_internal);

        $this->actingAsUser($this->aprovador);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('focus', $post->getKey())
            ->assertDontSee('SEGREDO');
    }

    public function test_cliente_nao_consegue_marcar_comentario_como_interno(): void
    {
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();

        $this->actingAsUser($this->aprovador);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('focus', $post->getKey())
            ->set('comentario', 'tentativa de comentário interno')
            ->set('comentarioInterno', true)
            ->call('comment', $post->getKey());

        $this->assertFalse(Comment::firstOrFail()->is_internal, 'Cliente não pode criar comentário interno.');
    }

    public function test_lembrete_de_prazo_sai_uma_vez_so(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();
        app(SendApprovalRequest::class)($post, $this->gestor->getKey());

        Approval::query()->update(['due_at' => now()->addHours(6)]);

        $this->artisan('approvals:remind')->assertSuccessful();
        Notification::assertSentToTimes($this->aprovador, ApprovalDeadlineApproaching::class, 1);

        // Segunda passagem do cron não repete o aviso.
        $this->artisan('approvals:remind')->assertSuccessful();
        Notification::assertSentToTimes($this->aprovador, ApprovalDeadlineApproaching::class, 1);
    }

    public function test_lembrete_ignora_prazo_distante(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);
        app(SendApprovalRequest::class)($this->postPronto(), $this->gestor->getKey());

        Approval::query()->update(['due_at' => now()->addDays(5)]);

        $this->artisan('approvals:remind')->assertSuccessful();

        // O convite inicial saiu; o lembrete de prazo, não.
        Notification::assertSentToTimes($this->aprovador, ApprovalDeadlineApproaching::class, 0);
    }

    public function test_pedido_duplicado_nao_gera_dois_registros(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();

        app(SendApprovalRequest::class)($post, $this->gestor->getKey());
        app(SendApprovalRequest::class)($post->refresh(), $this->gestor->getKey());

        $this->assertSame(1, Approval::where('post_id', $post->getKey())->count());
    }

    public function test_paginas_de_aprovacao_abrem_nos_dois_lados(): void
    {
        $this->actingAsUser($this->gestor)->get(route('painel.approvals'))->assertOk()->assertSee('Aprovações');
        $this->actingAsUser($this->aprovador)->get(route('portal.approvals'))->assertOk()->assertSee('Aprovações');
    }

    public function test_aprovacao_registra_quem_decidiu_e_por_qual_via(): void
    {
        Notification::fake();
        $this->actingAsUser($this->gestor);
        $post = $this->postPronto();
        app(SendApprovalRequest::class)($post, $this->gestor->getKey());

        $this->actingAsUser($this->aprovador);

        Livewire::test(ApprovalQueue::class, ['client' => $this->cliente, 'clientSide' => true])
            ->call('decide', $post->getKey(), 'approved');

        $aprovacao = Approval::firstOrFail();

        $this->assertSame($this->aprovador->getKey(), $aprovacao->decided_by);
        $this->assertSame($this->aprovador->name, $aprovacao->decided_by_name);
        $this->assertSame(ApprovalStatus::Approved, $aprovacao->status);
    }
}
