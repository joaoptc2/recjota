<?php

declare(strict_types=1);

namespace Tests\Feature\Posts;

use App\Livewire\Calendar\EditorialCalendar;
use App\Models\Client;
use App\Models\Post;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
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

    public function test_post_das_21h_utc_cai_no_dia_anterior_no_fuso_do_cliente(): void
    {
        // 02/04 às 00:30 UTC é 01/04 às 21:30 em Brasília. Agrupar em UTC
        // colocaria o post no dia errado do calendário.
        $post = Post::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'scheduled_at' => Carbon::parse('2026-04-02 00:30', 'UTC'),
        ]);

        $porDia = Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->set('anchor', '2026-04-01')
            ->instance()
            ->postsByDay();

        $this->assertArrayHasKey('2026-04-01', $porDia);
        $this->assertArrayNotHasKey('2026-04-02', $porDia);
        $this->assertTrue($porDia['2026-04-01']->contains('id', $post->getKey()));
    }

    public function test_arrastar_reagenda_preservando_o_horario(): void
    {
        $post = Post::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'scheduled_at' => Carbon::parse('2026-04-10 21:00', 'UTC'), // 18:00 em Brasília
        ]);

        Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->set('anchor', '2026-04-01')
            ->call('reschedule', $post->getKey(), '2026-04-17');

        $post->refresh();

        $this->assertSame('17/04/2026 18:00', display_datetime($post->scheduled_at, $this->cliente));
        $this->assertSame('2026-04-17 21:00:00', $post->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_post_publicado_nao_se_move_e_o_calendario_explica(): void
    {
        $post = Post::factory()->published()->create([
            'client_id' => $this->cliente->getKey(),
            'scheduled_at' => Carbon::parse('2026-04-10 21:00', 'UTC'),
        ]);

        $componente = Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->call('reschedule', $post->getKey(), '2026-04-17');

        $this->assertStringContainsString('não pode ser reagendado', $componente->get('feedback'));
        $this->assertSame('2026-04-10 21:00:00', $post->fresh()->scheduled_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_calendario_filtra_por_status_e_por_tipo(): void
    {
        Post::factory()->count(2)->create([
            'client_id' => $this->cliente->getKey(),
            'type' => PostType::Reel,
            'scheduled_at' => Carbon::parse('2026-05-10 15:00', 'UTC'),
        ]);
        Post::factory()->published()->create([
            'client_id' => $this->cliente->getKey(),
            'type' => PostType::FeedImage,
            'scheduled_at' => Carbon::parse('2026-05-12 15:00', 'UTC'),
        ]);

        $componente = Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->set('anchor', '2026-05-01');

        $this->assertCount(3, $componente->instance()->posts());

        $componente->set('type', PostType::Reel->value);
        $this->assertCount(2, $componente->instance()->posts());

        $componente->set('type', 'todos')->set('status', PostStatus::Published->value);
        $this->assertCount(1, $componente->instance()->posts());
    }

    public function test_grade_de_feed_mostra_somente_o_que_ja_foi_publicado(): void
    {
        Post::factory()->published()->create(['client_id' => $this->cliente->getKey()]);
        Post::factory()->count(2)->create(['client_id' => $this->cliente->getKey()]);

        $componente = Livewire::test(EditorialCalendar::class, ['client' => $this->cliente])
            ->call('setView', 'grade');

        $this->assertCount(1, $componente->instance()->posts());
    }

    public function test_visao_da_agencia_sem_cliente_agrega_todos_os_acessiveis(): void
    {
        $segundo = Client::factory()->configured()->create();
        $gestor = auth()->user();
        $gestor->clients()->attach($segundo->getKey(), ['role' => RoleName::Gestor->value]);
        $gestor->forgetAccessibleClients();
        $this->actingAsUser($gestor);

        Post::factory()->create(['client_id' => $this->cliente->getKey(), 'scheduled_at' => Carbon::parse('2026-06-10 15:00', 'UTC')]);
        Post::factory()->create(['client_id' => $segundo->getKey(), 'scheduled_at' => Carbon::parse('2026-06-11 15:00', 'UTC')]);

        $componente = Livewire::test(EditorialCalendar::class)->set('anchor', '2026-06-01');

        $this->assertCount(2, $componente->instance()->posts());
    }

    public function test_calendario_nao_mostra_post_de_cliente_alheio(): void
    {
        $alheio = Client::factory()->configured()->create();
        Post::factory()->count(3)->create(['client_id' => $alheio->getKey(), 'scheduled_at' => Carbon::parse('2026-07-10 15:00', 'UTC')]);

        $componente = Livewire::test(EditorialCalendar::class)->set('anchor', '2026-07-01');

        $this->assertCount(0, $componente->instance()->posts());
    }
}
