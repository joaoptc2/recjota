<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Client;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Comentário interno nunca chega a usuário do tipo client (Seção 5). */
class InternalCommentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_nao_ve_comentario_interno_na_tela_do_post(): void
    {
        $this->seedRoles();

        $client = Client::factory()->configured()->create();
        $post = Post::factory()->create(['client_id' => $client->getKey()]);

        $gestor = $this->userWithRole(RoleName::Gestor, $client);
        $aprovador = $this->userWithRole(RoleName::ClientAdmin, $client);

        Comment::factory()->forPost($post)->internal()->create([
            'user_id' => $gestor->getKey(),
            'body' => 'SEGREDO DA AGENCIA',
        ]);

        Comment::factory()->forPost($post)->create([
            'user_id' => $gestor->getKey(),
            'body' => 'Recado para o cliente',
        ]);

        $this->actingAsUser($aprovador)
            ->get(route('portal.posts.show', $post))
            ->assertOk()
            ->assertDontSee('SEGREDO DA AGENCIA')
            ->assertSee('Recado para o cliente');

        $this->actingAsUser($gestor)
            ->get(route('painel.posts.show', $post))
            ->assertOk()
            ->assertSee('SEGREDO DA AGENCIA');
    }

    public function test_policy_nega_leitura_de_comentario_interno_por_usuario_do_cliente(): void
    {
        $this->seedRoles();

        $client = Client::factory()->configured()->create();
        $post = Post::factory()->create(['client_id' => $client->getKey()]);
        $aprovador = $this->userWithRole(RoleName::ClientAdmin, $client);

        $interno = Comment::factory()->forPost($post)->internal()->create();

        $this->assertFalse($aprovador->can('view', $interno));
    }
}
