<?php

declare(strict_types=1);

namespace App\Actions\Approvals;

use App\Models\Approval;
use App\Models\Post;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use Illuminate\Support\Facades\DB;

/**
 * Abre o pedido de decisão do cliente (Seção 6.6).
 *
 * O pedido é sempre amarrado à VERSÃO atual do post. É esse vínculo que
 * permite, depois, responder "o cliente aprovou exatamente o quê?".
 */
class RequestApproval
{
    public function __invoke(Post $post, ?int $solicitanteId = null): Approval
    {
        return DB::transaction(function () use ($post, $solicitanteId): Approval {
            $prazoHoras = $post->client->settings?->approval_deadline_hours
                ?? config('agency.approval.default_deadline_hours');

            // Pedido pendente da mesma versão não se duplica: o cliente
            // receberia dois e-mails sobre a mesma coisa.
            $existente = $post->approvals()
                ->where('post_version', $post->current_version)
                ->where('status', ApprovalStatus::Pending->value)
                ->first();

            if ($existente !== null) {
                return $existente;
            }

            if ($post->status !== PostStatus::AwaitingClient) {
                $post->transitionTo(PostStatus::AwaitingClient);
                $post->save();
            }

            $post->forceFill(['approval_status' => ApprovalStatus::Pending])->save();

            return Approval::create([
                'client_id' => $post->client_id,
                'post_id' => $post->getKey(),
                'post_version' => $post->current_version,
                'requested_by' => $solicitanteId ?? auth()->id(),
                'requested_at' => now(),
                'due_at' => now()->addHours($prazoHoras),
                'status' => ApprovalStatus::Pending,
            ]);
        });
    }
}
