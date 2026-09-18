<?php

declare(strict_types=1);

namespace Tests\Feature\Posts;

use App\Actions\Posts\CreatePost;
use App\Actions\Posts\ReschedulePost;
use App\Actions\Posts\UpdatePost;
use App\Models\ApprovalLink;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Support\DataObjects\PostData;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class PostLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create(['timezone' => 'America/Sao_Paulo']);
        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));
    }

    public function test_criar_post_grava_a_versao_1_com_a_midia_na_ordem(): void
    {
        $midias = MediaAsset::factory()->count(5)->create(['client_id' => $this->cliente->getKey()]);

        $post = app(CreatePost::class)(new PostData(
            clientId: $this->cliente->getKey(),
            type: PostType::Carousel,
            caption: 'Carrossel de cinco fotos',
            media: $midias->map(fn (MediaAsset $m) => ['media_asset_id' => $m->getKey()])->all(),
        ));

        $this->assertSame(PostStatus::Draft, $post->status);
        $this->assertSame(1, $post->current_version);
        $this->assertCount(5, $post->postMedia);
        $this->assertSame([0, 1, 2, 3, 4], $post->postMedia->pluck('position')->all());

        $versao = $post->versions()->firstOrFail();
        $this->assertSame(1, $versao->version);
        $this->assertCount(5, $versao->snapshot['media']);
    }

    public function test_horario_digitado_no_fuso_do_cliente_vai_para_o_banco_em_utc(): void
    {
        $post = app(CreatePost::class)(PostData::fromForm([
            'type' => PostType::FeedImage->value,
            'caption' => 'Teste de fuso',
            'scheduled_at' => '2026-03-15 18:00',
        ], $this->cliente));

        // 18:00 em Brasília é 21:00 UTC.
        $this->assertSame('2026-03-15 21:00:00', $post->scheduled_at->utc()->format('Y-m-d H:i:s'));
        // E volta como 18:00 na tela.
        $this->assertSame('15/03/2026 18:00', display_datetime($post->scheduled_at, $this->cliente));
    }

    public function test_carrossel_recusa_o_decimo_primeiro_item(): void
    {
        $midias = MediaAsset::factory()->count(11)->create(['client_id' => $this->cliente->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no máximo 10/');

        app(CreatePost::class)(new PostData(
            clientId: $this->cliente->getKey(),
            type: PostType::Carousel,
            media: $midias->map(fn (MediaAsset $m) => ['media_asset_id' => $m->getKey()])->all(),
        ));
    }

    public function test_post_nao_aceita_midia_de_outro_cliente(): void
    {
        $outro = Client::factory()->configured()->create();
        $intrusa = MediaAsset::factory()->create(['client_id' => $outro->getKey()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/outro cliente/');

        app(CreatePost::class)(new PostData(
            clientId: $this->cliente->getKey(),
            type: PostType::FeedImage,
            media: [['media_asset_id' => $intrusa->getKey()]],
        ));
    }

    public function test_editar_post_aprovado_devolve_para_o_cliente_e_invalida_a_aprovacao(): void
    {
        $post = Post::factory()->approved()->create([
            'client_id' => $this->cliente->getKey(),
            'caption' => 'Texto aprovado',
        ]);
        // approved_version fica fora do fillable: é invariante interno.
        $post->forceFill(['approved_version' => $post->current_version])->save();

        $link = ApprovalLink::create([
            'client_id' => $this->cliente->getKey(),
            'post_id' => $post->getKey(),
            'token' => ApprovalLink::hashToken(ApprovalLink::generateToken()),
            'expires_at' => now()->addDays(7),
            'bound_version' => $post->current_version,
        ]);

        $atualizado = app(UpdatePost::class)($post, new PostData(
            clientId: $this->cliente->getKey(),
            type: PostType::FeedImage,
            caption: 'Texto alterado depois da aprovação',
        ));

        $this->assertSame(PostStatus::AwaitingClient, $atualizado->status);
        $this->assertSame(ApprovalStatus::Pending, $atualizado->approval_status);
        $this->assertNull($atualizado->approved_version, 'A aprovação era de outra versão.');
        $this->assertSame(2, $atualizado->current_version);
        $this->assertNotNull($link->fresh()->revoked_at, 'O link mágico da versão antiga precisa morrer.');
    }

    public function test_edicao_que_nao_muda_conteudo_nao_gera_versao_nova(): void
    {
        $post = Post::factory()->approved()->create([
            'client_id' => $this->cliente->getKey(),
            'caption' => 'Mesmo texto',
        ]);

        $atualizado = app(UpdatePost::class)($post, new PostData(
            clientId: $this->cliente->getKey(),
            type: $post->type,
            caption: 'Mesmo texto',
        ));

        $this->assertSame(1, $atualizado->current_version);
        $this->assertSame(PostStatus::Approved, $atualizado->status);
    }

    public function test_post_publicado_nao_se_move_no_calendario(): void
    {
        $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey()]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/não pode ser reagendado/');

        app(ReschedulePost::class)($post, Carbon::parse('2026-12-01 12:00', 'UTC'));
    }

    public function test_reagendar_rascunho_registra_a_mudanca_em_versao(): void
    {
        $post = Post::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'scheduled_at' => Carbon::parse('2026-03-15 21:00', 'UTC'),
        ]);

        $movido = app(ReschedulePost::class)($post, Carbon::parse('2026-03-20 23:00', 'UTC'));

        $this->assertSame('2026-03-20 23:00:00', $movido->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $movido->current_version);
        $this->assertStringContainsString('Reagendado', $movido->versions()->latest('version')->first()->change_summary);
    }

    public function test_cliente_sem_aprovacao_obrigatoria_nasce_com_post_dispensado(): void
    {
        $semAprovacao = Client::factory()->configured(['approval_required' => false])->create();

        // Precisa estar vinculado: a Action lê client_settings sob o escopo de
        // tenant, então um cliente inacessível simplesmente não tem settings.
        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $semAprovacao));

        $post = app(CreatePost::class)(new PostData(
            clientId: $semAprovacao->getKey(),
            type: PostType::FeedImage,
            caption: 'Sem aprovação',
        ));

        $this->assertSame(ApprovalStatus::NotRequired, $post->approval_status);
    }
}
