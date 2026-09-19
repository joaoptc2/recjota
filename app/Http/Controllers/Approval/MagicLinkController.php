<?php

declare(strict_types=1);

namespace App\Http\Controllers\Approval;

use App\Actions\Approvals\DecideApproval;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\ApprovalLink;
use App\Models\Post;
use App\Support\Approvals\MagicLinkResolver;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\DecisionChannel;
use App\Support\Enums\PostStatus;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Aprovação por link mágico (Seção 6.6).
 *
 * Sem login, sem senha, sem app. O cliente abre o e-mail no celular, vê tudo
 * numa tela só e decide num toque. Reduzir esse atrito é o produto inteiro.
 */
class MagicLinkController extends Controller
{
    public function __construct(private readonly MagicLinkResolver $resolver) {}

    public function show(string $token): View
    {
        $link = $this->resolver->resolve($token);

        if (! $link->isUsable()) {
            return view('approval.indisponivel', [
                'motivo' => $link->reasonUnusable(),
                'client' => $link->client,
            ]);
        }

        abort_if($link->post === null, 404);

        $this->resolver->registerUse($link);

        return view('approval.post', [
            'link' => $link,
            'token' => $token,
            'post' => $link->post,
            'client' => $link->client,
            'aprovacao' => $this->pendente($link->post),
            'comentarios' => $link->post->comments()->where('is_internal', false)->with('user')->oldest()->get(),
        ]);
    }

    public function batch(string $token): View
    {
        $link = $this->resolver->resolve($token);

        if (! $link->isUsable()) {
            return view('approval.indisponivel', [
                'motivo' => $link->reasonUnusable(),
                'client' => $link->client,
            ]);
        }

        $this->resolver->registerUse($link);

        return view('approval.lote', [
            'link' => $link,
            'token' => $token,
            'client' => $link->client,
            'posts' => $this->pendentesDoCliente($link),
        ]);
    }

    public function decide(Request $request, string $token): RedirectResponse
    {
        $link = $this->resolver->resolve($token);

        abort_unless($link->isUsable(), 410, $link->reasonUnusable() ?? 'Link indisponível.');

        $dados = $request->validate([
            'post_id' => ['required', 'integer'],
            'decisao' => ['required', 'in:approved,rejected,changes_requested'],
            'nota' => ['nullable', 'string', 'max:2000'],
            'nome' => ['nullable', 'string', 'max:120'],
        ], [
            'decisao.in' => 'Decisão inválida.',
        ]);

        $post = Post::findOrFail($dados['post_id']);

        // Um link de post único só decide o post dele.
        abort_if($link->post_id !== null && $link->post_id !== $post->getKey(), 403);
        abort_if($post->client_id !== $link->client_id, 403);

        $aprovacao = $this->pendente($post);

        if ($aprovacao === null) {
            return back()->withErrors(['decisao' => 'Este post não está aguardando decisão.']);
        }

        try {
            app(DecideApproval::class)(
                aprovacao: $aprovacao,
                decisao: ApprovalStatus::from($dados['decisao']),
                nota: $dados['nota'] ?? null,
                via: DecisionChannel::MagicLink,
                decisorId: null,
                decisorNome: $dados['nome'] ?? $link->recipient_name ?? 'Cliente',
            );
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['nota' => $e->getMessage()]);
        }

        $this->resolver->registerUse($link);

        return back()->with('status', match ($dados['decisao']) {
            'approved' => 'Aprovado. A equipe já foi avisada.',
            'rejected' => 'Reprovado. A equipe recebeu o seu motivo.',
            default => 'Ajuste solicitado. A equipe recebeu o seu recado.',
        });
    }

    private function pendente(Post $post): ?Approval
    {
        return $post->approvals()
            ->where('post_version', $post->current_version)
            ->where('status', ApprovalStatus::Pending->value)
            ->first();
    }

    /** @return Collection<int, Post> */
    private function pendentesDoCliente(ApprovalLink $link): Collection
    {
        return Post::query()
            ->where('client_id', $link->client_id)
            ->where('status', PostStatus::AwaitingClient->value)
            ->with(['postMedia.mediaAsset', 'socialAccount'])
            ->orderBy('scheduled_at')
            ->get();
    }
}
