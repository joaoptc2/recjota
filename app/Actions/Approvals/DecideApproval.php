<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\Approval;
use App\Models\Comment;
use App\Models\Post;
use App\Notifications\ApprovalDecided;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\DecisionChannel;
use App\Support\Enums\PostStatus;
use App\Support\Enums\RoleName;
use App\Support\Enums\UserType;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Registra a decisão do cliente (Seção 6.6).
 *
 * Três saídas explícitas: aprovar, pedir ajustes (exige comentário) e reprovar
 * (exige motivo). A decisão vale para a versão que estava na tela, nunca para
 * "o post" em abstrato.
 */
class DecideApproval
{
    public function __construct(private readonly SchedulePostAfterApproval $agendar) {}

    public function __invoke(
        Approval $aprovacao,
        ApprovalStatus $decisao,
        ?string $nota = null,
        DecisionChannel $via = DecisionChannel::Portal,
        ?int $decisorId = null,
        ?string $decisorNome = null,
    ): Post {
        if (! $decisao->isDecision()) {
            throw new DomainException('Decisão inválida.');
        }

        if ($aprovacao->status !== ApprovalStatus::Pending) {
            throw new DomainException('Este post já foi decidido.');
        }

        if ($decisao !== ApprovalStatus::Approved && blank($nota)) {
            throw new DomainException($decisao === ApprovalStatus::Rejected
                ? 'Diga o motivo da reprovação: sem isso a equipe não sabe o que corrigir.'
                : 'Descreva o ajuste desejado.');
        }

        return DB::transaction(function () use ($aprovacao, $decisao, $nota, $via, $decisorId, $decisorNome): Post {
            $post = $aprovacao->post()->firstOrFail();

            // A tela mostrava a versão X; se o post mudou nesse meio-tempo, a
            // decisão não vale para o que está lá agora.
            if ($aprovacao->post_version !== $post->current_version) {
                throw new DomainException(
                    'O post foi alterado depois que este pedido foi enviado. Peça um link atualizado à agência.',
                );
            }

            $aprovacao->forceFill([
                'status' => $decisao,
                'decided_by' => $decisorId,
                'decided_by_name' => $decisorNome,
                'decided_at' => now(),
                'decision_note' => $nota,
                'decided_via' => $via,
            ])->save();

            if (filled($nota)) {
                Comment::create([
                    'client_id' => $post->client_id,
                    'commentable_type' => Post::class,
                    'commentable_id' => $post->getKey(),
                    'user_id' => $decisorId,
                    'guest_name' => $decisorId === null ? $decisorNome : null,
                    'body' => $nota,
                    'is_internal' => false,
                    'post_version' => $post->current_version,
                ]);
            }

            $post->transitionTo(match ($decisao) {
                ApprovalStatus::Approved => PostStatus::Approved,
                ApprovalStatus::Rejected => PostStatus::Rejected,
                default => PostStatus::ChangesRequested,
            });

            $post->approval_status = $decisao;

            if ($decisao === ApprovalStatus::Approved) {
                // Fica registrado QUAL versão foi aprovada.
                $post->approved_version = $post->current_version;
            }

            $post->save();

            if ($decisao === ApprovalStatus::Approved) {
                ($this->agendar)($post);
            }

            $this->avisarEquipe($post, $aprovacao);

            return $post->refresh();
        });
    }

    /**
     * A equipe precisa saber da decisão sem depender de alguém abrir o painel
     * — principalmente quando o cliente pediu ajuste (Seção 6.11).
     */
    private function avisarEquipe(Post $post, Approval $aprovacao): void
    {
        $equipe = $post->client->users()
            ->where('users.type', UserType::Agency->value)
            ->where('users.is_active', true)
            ->whereIn('client_user.role', [RoleName::Gestor->value, RoleName::Criador->value])
            ->get();

        if ($equipe->isEmpty()) {
            return;
        }

        Notification::send($equipe, new ApprovalDecided($post, $aprovacao));
    }
}
