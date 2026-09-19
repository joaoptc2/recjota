<?php

declare(strict_types=1);

namespace Tests\Feature\Approvals;

use App\Actions\Approvals\IssueApprovalLink;
use App\Actions\Approvals\RequestApproval;
use App\Actions\Posts\UpdatePost;
use App\Models\ApprovalLink;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\DataObjects\PostData;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\DecisionChannel;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Critério de aceite da Fase 3: um link mágico enviado por e-mail permite
 * aprovar um post no celular, sem login, em no máximo dois toques — e a
 * aprovação fica registrada contra a versão exata.
 */
class MagicLinkApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    private Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create(['timezone' => 'America/Sao_Paulo']);

        $gestor = $this->userWithRole(RoleName::Gestor, $this->cliente);
        $this->actingAsUser($gestor);

        $this->post = Post::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'caption' => 'Peça para aprovação',
            'status' => PostStatus::Draft,
        ]);
        $this->post->media()->attach(
            MediaAsset::factory()->create(['client_id' => $this->cliente->getKey()])->getKey(),
            ['position' => 0],
        );
    }

    /** Emite o link como a agência faria, e devolve o token em claro. */
    private function emitirLink(): array
    {
        app(RequestApproval::class)($this->post->refresh());

        return app(IssueApprovalLink::class)->forPost($this->post->refresh(), 'cliente@exemplo.test', 'Marcos');
    }

    public function test_criterio_de_aceite_aprova_sem_login_em_dois_toques(): void
    {
        ['token' => $token, 'url' => $url] = $this->emitirLink();
        $versaoNaTela = $this->post->refresh()->current_version;

        // Sai da sessão: o cliente abre o link no celular, sem estar logado.
        auth()->logout();
        $this->flushSession();

        // Toque 1 — abrir o link do e-mail.
        $this->get($url)
            ->assertOk()
            ->assertSee('Peça para aprovação')
            ->assertSee('Aprovar');

        // Aprovar não pode exigir login: é o ponto inteiro do link mágico.
        $this->assertGuest();

        // Toque 2 — tocar em Aprovar.
        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'approved',
        ])->assertRedirect();

        $post = $this->post->refresh();

        $this->assertSame(PostStatus::Approved, $post->status);
        $this->assertSame(ApprovalStatus::Approved, $post->approval_status);

        // A aprovação ficou amarrada à versão exata que estava na tela.
        $this->assertSame($versaoNaTela, $post->approved_version);

        $aprovacao = $post->approvals()->firstOrFail();
        $this->assertSame(ApprovalStatus::Approved, $aprovacao->status);
        $this->assertSame($versaoNaTela, $aprovacao->post_version);
        $this->assertSame(DecisionChannel::MagicLink, $aprovacao->decided_via);
        $this->assertSame('Marcos', $aprovacao->decided_by_name);
        $this->assertNotNull($aprovacao->decided_at);
    }

    public function test_reprovar_exige_motivo(): void
    {
        ['token' => $token] = $this->emitirLink();
        auth()->logout();

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'rejected',
        ])->assertSessionHasErrors('nota');

        $this->assertSame(PostStatus::AwaitingClient, $this->post->refresh()->status);
    }

    public function test_pedido_de_ajuste_vira_comentario_visivel_ao_cliente(): void
    {
        ['token' => $token] = $this->emitirLink();
        auth()->logout();

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'changes_requested',
            'nota' => 'Trocar a foto principal, por favor.',
            'nome' => 'Marcos',
        ])->assertRedirect();

        $post = $this->post->refresh();

        $this->assertSame(PostStatus::ChangesRequested, $post->status);

        $comentario = $post->comments()->firstOrFail();
        $this->assertSame('Trocar a foto principal, por favor.', $comentario->body);
        $this->assertFalse($comentario->is_internal);
        $this->assertSame('Marcos', $comentario->guest_name);
    }

    public function test_editar_o_post_derruba_o_link_ja_enviado(): void
    {
        ['token' => $token, 'url' => $url] = $this->emitirLink();

        app(UpdatePost::class)($this->post, new PostData(
            clientId: $this->cliente->getKey(),
            type: PostType::FeedImage,
            caption: 'Texto trocado depois de enviar o link',
        ));

        auth()->logout();

        // O que a pessoa veria no e-mail não existe mais.
        $this->get($url)->assertOk()->assertSee('não está mais válido');

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'approved',
        ])->assertStatus(410);
    }

    public function test_link_expirado_nao_decide(): void
    {
        ['token' => $token, 'link' => $link] = $this->emitirLink();
        $link->forceFill(['expires_at' => now()->subHour()])->save();
        auth()->logout();

        $this->get(route('aprovacao.post', $token))->assertOk()->assertSee('Este link expirou');
        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'approved',
        ])->assertStatus(410);
    }

    public function test_link_revogado_nao_decide(): void
    {
        ['token' => $token, 'link' => $link] = $this->emitirLink();
        $link->forceFill(['revoked_at' => now()])->save();
        auth()->logout();

        $this->get(route('aprovacao.post', $token))->assertOk()->assertSee('revogado');
    }

    public function test_link_de_um_cliente_nao_decide_post_de_outro(): void
    {
        ['token' => $token] = $this->emitirLink();

        $outro = Client::factory()->configured()->create();
        $postAlheio = Post::factory()->awaitingClient()->create(['client_id' => $outro->getKey()]);

        auth()->logout();

        // 404 e não 403: para um visitante anônimo, confirmar que o post
        // existe já seria informação demais. O escopo de tenant trancado no
        // cliente do link faz o registro sumir antes de qualquer checagem.
        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $postAlheio->getKey(),
            'decisao' => 'approved',
        ])->assertNotFound();

        $this->assertSame(PostStatus::AwaitingClient, $postAlheio->refresh()->status);
    }

    public function test_token_em_claro_nunca_chega_ao_banco(): void
    {
        ['token' => $token] = $this->emitirLink();

        $this->assertDatabaseMissing('approval_links', ['token' => $token]);
        $this->assertDatabaseHas('approval_links', ['token' => ApprovalLink::hashToken($token)]);
    }

    public function test_token_invalido_devolve_404(): void
    {
        $this->get(route('aprovacao.post', str_repeat('z', 64)))->assertNotFound();
    }

    public function test_segunda_decisao_no_mesmo_post_e_recusada(): void
    {
        ['token' => $token] = $this->emitirLink();
        auth()->logout();

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'approved',
        ])->assertRedirect();

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'rejected',
            'nota' => 'mudei de ideia',
        ])->assertSessionHasErrors('decisao');
    }

    public function test_aprovacao_com_publicacao_automatica_ja_agenda(): void
    {
        $this->cliente->settings->update(['auto_publish_on_approval' => true]);
        $this->post->forceFill(['scheduled_at' => now()->addDays(2)])->save();

        ['token' => $token] = $this->emitirLink();
        auth()->logout();

        $this->post(route('aprovacao.decidir', $token), [
            'post_id' => $this->post->getKey(),
            'decisao' => 'approved',
        ])->assertRedirect();

        $this->assertSame(PostStatus::Scheduled, $this->post->refresh()->status);
    }
}
