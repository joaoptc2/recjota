<?php

declare(strict_types=1);

namespace App\Actions\Posts;

use App\Models\Post;
use App\Support\DataObjects\PostData;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use Illuminate\Support\Facades\DB;

/**
 * Editar um post já enviado ao cliente gera versão nova e devolve o post para
 * aprovação (Seção 6.6).
 *
 * É a regra que impede o pior cenário do produto: publicar algo diferente do
 * que o cliente aprovou.
 */
class UpdatePost
{
    public function __construct(
        private readonly SyncPostMedia $sincronizarMidia,
        private readonly RecordPostVersion $registrarVersao,
    ) {}

    public function __invoke(Post $post, PostData $dados, ?string $resumo = null): Post
    {
        return DB::transaction(function () use ($post, $dados, $resumo): Post {
            $antes = $this->conteudoRelevante($post);

            $post->fill($dados->toAttributes());

            if ($dados->media !== []) {
                ($this->sincronizarMidia)($post, $dados->media);
            }

            $post->save();
            $post->refresh();

            $mudou = $this->conteudoRelevante($post) !== $antes;

            if (! $mudou) {
                return $post;
            }

            $post->increment('current_version');
            $post->refresh();

            if ($this->precisaVoltarParaOCliente($post)) {
                $post->transitionTo(PostStatus::AwaitingClient);
                $post->approval_status = ApprovalStatus::Pending;
                // A aprovação anterior deixa de valer: era de outra versão.
                $post->approved_version = null;
                $post->save();

                $this->revogarLinksDaVersaoAntiga($post);
            }

            ($this->registrarVersao)($post, $resumo ?? 'Conteúdo alterado', $dados->createdBy);

            return $post->fresh(['postMedia.mediaAsset', 'client', 'socialAccount']);
        });
    }

    /** Só o que o cliente vê conta como mudança digna de nova versão. */
    private function conteudoRelevante(Post $post): string
    {
        $post->loadMissing('postMedia');

        return json_encode([
            'caption' => $post->caption,
            'first_comment' => $post->first_comment,
            'type' => $post->type->value,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'social_account_id' => $post->social_account_id,
            'media' => $post->postMedia
                ->sortBy('position')
                ->map(fn ($m) => [$m->media_asset_id, $m->position, $m->alt_text])
                ->values()
                ->all(),
        ], JSON_THROW_ON_ERROR);
    }

    private function precisaVoltarParaOCliente(Post $post): bool
    {
        return in_array($post->status, [
            PostStatus::Approved,
            PostStatus::Scheduled,
        ], true);
    }

    /**
     * Link mágico emitido para a versão anterior não pode mais decidir nada.
     *
     * Vale também para link sem versão declarada: se ele aponta para ESTE post
     * e o post mudou, o que quem recebeu o e-mail viu não existe mais.
     */
    private function revogarLinksDaVersaoAntiga(Post $post): void
    {
        $post->approvalLinks()
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q
                ->whereNull('bound_version')
                ->orWhere('bound_version', '<', $post->current_version))
            ->update(['revoked_at' => now()]);
    }
}
