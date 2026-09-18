<?php

declare(strict_types=1);

namespace App\Actions\Posts;

use App\Models\Client;
use App\Models\Post;
use App\Support\DataObjects\PostData;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use Illuminate\Support\Facades\DB;

class CreatePost
{
    public function __construct(
        private readonly SyncPostMedia $sincronizarMidia,
        private readonly RecordPostVersion $registrarVersao,
    ) {}

    public function __invoke(PostData $dados): Post
    {
        return DB::transaction(function () use ($dados): Post {
            $cliente = Client::withoutClientScope()->findOrFail($dados->clientId);

            $post = Post::create([
                ...$dados->toAttributes(),
                'status' => PostStatus::Draft,
                // Cliente que dispensou aprovação não fica com post "pendente"
                // para sempre num painel que ninguém olha.
                'approval_status' => $cliente->settings?->approval_required === false
                    ? ApprovalStatus::NotRequired
                    : ApprovalStatus::Pending,
            ]);

            // current_version nasce em 1 pelo default da coluna: é invariante
            // interno, não campo de formulário, e por isso fica fora do
            // fillable. O refresh traz o valor gravado para a instância.
            $post->refresh();

            if ($dados->media !== []) {
                ($this->sincronizarMidia)($post, $dados->media);
            }

            ($this->registrarVersao)($post, 'Rascunho criado', $dados->createdBy);

            return $post->fresh(['postMedia.mediaAsset', 'client', 'socialAccount']);
        });
    }
}
