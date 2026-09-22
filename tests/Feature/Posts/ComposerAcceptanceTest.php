<?php

declare(strict_types=1);

namespace Tests\Feature\Posts;

use App\Livewire\Calendar\EditorialCalendar;
use App\Livewire\Posts\PostComposer;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Critério de aceite da Fase 2: criar um post com carrossel de 5 imagens,
 * agendar, e vê-lo posicionado corretamente no calendário no fuso do cliente.
 */
class ComposerAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private Client $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->cliente = Client::factory()->configured()->create([
            'name' => 'Acme Café',
            'timezone' => 'America/Sao_Paulo',
        ]);
        $this->actingAsUser($this->userWithRole(RoleName::Gestor, $this->cliente));
    }

    public function test_criterio_de_aceite_carrossel_de_cinco_imagens_agendado_aparece_no_calendario(): void
    {
        $conta = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $midias = MediaAsset::factory()->count(5)->create(['client_id' => $this->cliente->getKey()]);

        $composer = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('type', PostType::Carousel->value)
            ->set('socialAccountId', $conta->getKey())
            ->set('caption', 'Chegou a nova linha. Deslize para ver tudo. #novidade');

        foreach ($midias as $midia) {
            $composer->call('toggleMedia', $midia->getKey());
        }

        // 15/03/2026 às 18:00 no horário de Brasília.
        $composer->set('scheduledAt', '2026-03-15T18:00')
            ->assertSet('type', PostType::Carousel->value)
            ->call('save');

        $post = Post::firstOrFail();

        $this->assertSame(PostType::Carousel, $post->type);
        $this->assertCount(5, $post->postMedia);
        $this->assertSame([0, 1, 2, 3, 4], $post->postMedia->sortBy('position')->pluck('position')->all());

        // No banco, UTC. Na tela, Brasília.
        $this->assertSame('2026-03-15 21:00:00', $post->scheduled_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('15/03/2026 18:00', display_datetime($post->scheduled_at, $this->cliente));

        // E o calendário coloca no dia 15 — não no 16, que seria o erro se o
        // agrupamento fosse feito em UTC.
        Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->set('anchor', '2026-03-01')
            ->assertSee('15/03')
            ->tap(function ($componente) use ($post) {
                $porDia = $componente->instance()->postsByDay();

                $this->assertArrayHasKey('2026-03-15', $porDia);
                $this->assertTrue($porDia['2026-03-15']->contains('id', $post->getKey()));
                $this->assertArrayNotHasKey('2026-03-16', $porDia);
            });
    }

    public function test_composer_bloqueia_envio_sem_midia(): void
    {
        Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('caption', 'Só texto')
            ->tap(fn ($c) => $this->assertContains(
                'Adicione ao menos uma mídia: o Instagram não publica post só com texto.',
                $c->instance()->blockingIssues(),
            ));
    }

    public function test_composer_conta_caracteres_hashtags_e_mencoes(): void
    {
        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('caption', 'Olá @joao e @maria #um #dois #tres');

        $this->assertSame(2, $componente->instance()->mentionCount());
        $this->assertSame(3, $componente->instance()->hashtagCount());
        $this->assertSame(34, $componente->instance()->captionLength());
    }

    public function test_composer_avisa_o_ponto_de_corte_do_feed(): void
    {
        $curto = Livewire::test(PostComposer::class, ['client' => $this->cliente])->set('caption', str_repeat('a', 100));
        $longo = Livewire::test(PostComposer::class, ['client' => $this->cliente])->set('caption', str_repeat('a', 300));

        $this->assertFalse($curto->instance()->captionPreviewTruncated());
        $this->assertTrue($longo->instance()->captionPreviewTruncated());
    }

    public function test_composer_bloqueia_legenda_acima_de_2200_caracteres(): void
    {
        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('caption', str_repeat('a', 2300));

        $this->assertContains('A legenda tem 2300 caracteres; o limite é 2200.', $componente->instance()->blockingIssues());
    }

    public function test_composer_bloqueia_o_decimo_primeiro_item_do_carrossel(): void
    {
        $midias = MediaAsset::factory()->count(11)->create(['client_id' => $this->cliente->getKey()]);

        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('type', PostType::Carousel->value);

        foreach ($midias as $midia) {
            $componente->call('toggleMedia', $midia->getKey());
        }

        $this->assertCount(10, $componente->get('mediaIds'));
        $componente->assertHasErrors('media');
    }

    public function test_carrossel_com_um_item_so_e_bloqueado(): void
    {
        $midia = MediaAsset::factory()->create(['client_id' => $this->cliente->getKey()]);

        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('type', PostType::Carousel->value)
            ->call('toggleMedia', $midia->getKey());

        $this->assertContains(
            'Carrossel precisa de pelo menos 2 itens; com um só, escolha Imagem ou Vídeo.',
            $componente->instance()->blockingIssues(),
        );
    }

    public function test_post_sem_conta_do_instagram_e_bloqueado_com_o_proximo_passo(): void
    {
        $semConta = Livewire::test(PostComposer::class, ['client' => $this->cliente]);

        $this->assertContains(
            'Este cliente ainda não tem conta do Instagram conectada. Conecte uma na página do cliente antes de agendar.',
            $semConta->instance()->blockingIssues(),
        );

        SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);

        $semEscolha = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('socialAccountId', null);

        $this->assertContains(
            'Escolha a conta do Instagram que vai publicar este post.',
            $semEscolha->instance()->blockingIssues(),
        );
    }

    public function test_story_em_conta_creator_e_bloqueado(): void
    {
        $creator = SocialAccount::factory()->creator()->create(['client_id' => $this->cliente->getKey()]);
        $midia = MediaAsset::factory()->create(['client_id' => $this->cliente->getKey(), 'width' => 1080, 'height' => 1920]);

        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('type', PostType::Story->value)
            ->set('socialAccountId', $creator->getKey())
            ->call('toggleMedia', $midia->getKey());

        $this->assertContains(
            'Stories por API exigem conta Business. Esta conta é Creator.',
            $componente->instance()->blockingIssues(),
        );
    }

    public function test_composer_mostra_o_horario_local_e_o_utc_lado_a_lado(): void
    {
        $componente = Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('scheduledAt', '2026-03-15T18:00');

        $this->assertSame(
            '15/03/2026 18:00 (horário de Brasília) — 15/03/2026 21:00 UTC',
            $componente->instance()->scheduleHint(),
        );
    }

    public function test_composicao_para_varias_contas_gera_posts_irmaos_independentes(): void
    {
        $principal = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $segunda = SocialAccount::factory()->create(['client_id' => $this->cliente->getKey()]);
        $midia = MediaAsset::factory()->create(['client_id' => $this->cliente->getKey()]);

        Livewire::test(PostComposer::class, ['client' => $this->cliente])
            ->set('socialAccountId', $principal->getKey())
            ->set('extraAccountIds', [$segunda->getKey()])
            ->set('caption', 'Mesma peça, duas contas')
            ->call('toggleMedia', $midia->getKey())
            ->call('save');

        $this->assertSame(2, Post::count());

        $irmao = Post::where('social_account_id', $segunda->getKey())->firstOrFail();
        $pai = Post::where('social_account_id', $principal->getKey())->firstOrFail();

        $this->assertSame($pai->getKey(), $irmao->sibling_group_id);
        $this->assertSame(PostStatus::Draft, $irmao->status);
        $this->assertCount(1, $irmao->postMedia);
    }
}
