<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Models\Post;
use App\Support\Enums\PostStatus;
use App\Support\Exceptions\InvalidStateTransition;
use PHPUnit\Framework\TestCase;

/**
 * Máquina de estados do post (Seção 5 / 13): toda transição ilegal lança
 * exceção de domínio.
 */
class PostStatusTransitionTest extends TestCase
{
    public function test_o_caminho_feliz_completo_e_permitido(): void
    {
        $caminho = [
            PostStatus::Draft,
            PostStatus::InReview,
            PostStatus::AwaitingClient,
            PostStatus::Approved,
            PostStatus::Scheduled,
            PostStatus::Publishing,
            PostStatus::Published,
        ];

        for ($i = 0; $i < count($caminho) - 1; $i++) {
            $this->assertTrue(
                $caminho[$i]->canTransitionTo($caminho[$i + 1]),
                sprintf('%s → %s deveria ser permitido', $caminho[$i]->value, $caminho[$i + 1]->value),
            );
        }
    }

    public function test_rascunho_nao_pula_direto_para_publicando(): void
    {
        $this->expectException(InvalidStateTransition::class);

        PostStatus::Draft->assertCanTransitionTo(PostStatus::Publishing);
    }

    public function test_publicado_e_terminal(): void
    {
        $this->assertTrue(PostStatus::Published->isTerminal());
        $this->assertSame([], PostStatus::Published->allowedTransitions());

        $this->expectException(InvalidStateTransition::class);

        PostStatus::Published->assertCanTransitionTo(PostStatus::Draft);
    }

    public function test_falha_volta_para_publicando_no_retry(): void
    {
        $this->assertTrue(PostStatus::Publishing->canTransitionTo(PostStatus::Failed));
        $this->assertTrue(PostStatus::Failed->canTransitionTo(PostStatus::Publishing));
    }

    public function test_editar_post_aprovado_devolve_para_o_cliente(): void
    {
        $this->assertTrue(PostStatus::Approved->canTransitionTo(PostStatus::AwaitingClient));
        $this->assertTrue(PostStatus::Scheduled->canTransitionTo(PostStatus::AwaitingClient));
    }

    public function test_post_publicado_nao_se_move_no_calendario(): void
    {
        $this->assertFalse(PostStatus::Published->isMovableInCalendar());
        $this->assertFalse(PostStatus::Publishing->isMovableInCalendar());
        $this->assertTrue(PostStatus::Scheduled->isMovableInCalendar());
    }

    public function test_model_recusa_transicao_ilegal(): void
    {
        $post = new Post(['status' => PostStatus::Draft]);
        $post->status = PostStatus::Draft;

        $this->expectException(InvalidStateTransition::class);

        $post->transitionTo(PostStatus::Published);
    }

    public function test_model_aceita_transicao_legal(): void
    {
        $post = new Post;
        $post->status = PostStatus::Draft;

        $post->transitionTo(PostStatus::InReview);

        $this->assertSame(PostStatus::InReview, $post->status);
    }

    public function test_nenhum_estado_transiciona_para_si_mesmo(): void
    {
        foreach (PostStatus::cases() as $status) {
            $this->assertNotContains(
                $status,
                $status->allowedTransitions(),
                sprintf('%s não deveria transicionar para si mesmo', $status->value),
            );
        }
    }
}
