<?php

declare(strict_types=1);

namespace App\Livewire\Approvals;

use App\Actions\Approvals\DecideApproval;
use App\Actions\Approvals\SendApprovalRequest;
use App\Models\Client;
use App\Models\Comment;
use App\Models\Post;
use App\Support\Approvals\WhatsAppMessage;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\DecisionChannel;
use App\Support\Enums\PostStatus;
use App\Support\Enums\RoleName;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Fila de aprovação (Seção 6.6).
 *
 * Serve os dois lados: a agência vê o que está travado e com quem, e envia ou
 * reenvia o link; o cliente logado decide sem precisar do e-mail.
 */
class ApprovalQueue extends Component
{
    public ?Client $client = null;

    /** true quando renderizada dentro do portal do cliente. */
    public bool $clientSide = false;

    #[Url]
    public string $filtro = 'pendentes';

    /** @var array<int, array{email: ?string, url: string, nome: ?string}> */
    public array $linksEmitidos = [];

    public ?int $postEmFoco = null;

    public string $nota = '';

    public string $comentario = '';

    public bool $comentarioInterno = false;

    public ?string $feedback = null;

    public function mount(?Client $client = null, bool $clientSide = false): void
    {
        $this->client = $client?->exists ? $client : null;
        $this->clientSide = $clientSide;
    }

    #[Computed]
    public function posts(): Collection
    {
        return Post::query()
            ->with(['client.settings', 'socialAccount', 'postMedia.mediaAsset', 'approvals'])
            ->when($this->client !== null, fn ($q) => $q->where('client_id', $this->client->getKey()))
            ->when($this->filtro === 'pendentes', fn ($q) => $q->whereIn('status', [
                PostStatus::AwaitingClient->value,
                PostStatus::InReview->value,
            ]))
            ->when($this->filtro === 'ajustes', fn ($q) => $q->where('status', PostStatus::ChangesRequested->value))
            ->when($this->filtro === 'reprovados', fn ($q) => $q->where('status', PostStatus::Rejected->value))
            ->when($this->filtro === 'prontos', fn ($q) => $q->whereIn('status', [
                PostStatus::Approved->value,
                PostStatus::Scheduled->value,
            ]))
            ->orderByRaw('scheduled_at is null, scheduled_at')
            ->get();
    }

    /** Posts sem decisão há mais tempo do que o prazo: o painel do travado. */
    #[Computed]
    public function atrasados(): Collection
    {
        return $this->posts()->filter(
            fn (Post $p) => $p->approvals->firstWhere('status', ApprovalStatus::Pending)?->isOverdue() === true,
        );
    }

    public function send(int $postId): void
    {
        $post = Post::findOrFail($postId);
        $this->authorize('requestApproval', $post);

        $this->linksEmitidos = app(SendApprovalRequest::class)($post, auth()->id());
        $this->feedback = count($this->linksEmitidos) === 1 && $this->linksEmitidos[0]['email'] === null
            ? 'Nenhum aprovador cadastrado para este cliente — gerei um link para você enviar à mão.'
            : sprintf('Link enviado para %d pessoa(s).', count($this->linksEmitidos));

        unset($this->posts, $this->atrasados);
    }

    public function whatsappUrl(int $postId, string $url): string
    {
        $post = Post::findOrFail($postId);

        return WhatsAppMessage::forApproval($post, $url);
    }

    public function focus(?int $postId): void
    {
        $this->postEmFoco = $this->postEmFoco === $postId ? null : $postId;
        $this->nota = '';
        $this->comentario = '';
    }

    /** Decisão de quem está autenticado no portal (Seção 6.6). */
    public function decide(int $postId, string $decisao): void
    {
        $post = Post::findOrFail($postId);
        $this->authorize('decide', $post);

        $aprovacao = $post->approvals()
            ->where('post_version', $post->current_version)
            ->where('status', ApprovalStatus::Pending->value)
            ->first();

        if ($aprovacao === null) {
            $this->feedback = 'Este post não está aguardando decisão.';

            return;
        }

        try {
            app(DecideApproval::class)(
                aprovacao: $aprovacao,
                decisao: ApprovalStatus::from($decisao),
                nota: $this->nota !== '' ? $this->nota : null,
                via: DecisionChannel::Portal,
                decisorId: auth()->id(),
                decisorNome: auth()->user()->name,
            );

            $this->feedback = 'Decisão registrada. A equipe foi avisada.';
            $this->postEmFoco = null;
            $this->nota = '';
        } catch (DomainException $e) {
            $this->addError('nota', $e->getMessage());
        }

        unset($this->posts, $this->atrasados);
    }

    /** Aprovação em lote: tudo o que está pendente, de uma vez (Seção 6.6). */
    public function approveAll(): void
    {
        $aprovados = 0;

        foreach ($this->posts() as $post) {
            if ($post->status !== PostStatus::AwaitingClient || ! auth()->user()->can('decide', $post)) {
                continue;
            }

            $aprovacao = $post->approvals()
                ->where('post_version', $post->current_version)
                ->where('status', ApprovalStatus::Pending->value)
                ->first();

            if ($aprovacao === null) {
                continue;
            }

            app(DecideApproval::class)(
                aprovacao: $aprovacao,
                decisao: ApprovalStatus::Approved,
                via: DecisionChannel::Portal,
                decisorId: auth()->id(),
                decisorNome: auth()->user()->name,
            );

            $aprovados++;
        }

        $this->feedback = $aprovados === 0
            ? 'Nada pendente para aprovar.'
            : sprintf('%d post(s) aprovado(s).', $aprovados);

        unset($this->posts, $this->atrasados);
    }

    public function comment(int $postId): void
    {
        $post = Post::findOrFail($postId);
        $this->authorize('view', $post);

        $this->validate(['comentario' => ['required', 'string', 'max:2000']], [], ['comentario' => 'comentário']);

        $interno = $this->comentarioInterno && auth()->user()->can('createInternal', Comment::class);

        Comment::create([
            'client_id' => $post->client_id,
            'commentable_type' => Post::class,
            'commentable_id' => $post->getKey(),
            'user_id' => auth()->id(),
            'body' => $this->comentario,
            'is_internal' => $interno,
            'post_version' => $post->current_version,
        ]);

        $this->comentario = '';
        $this->feedback = $interno ? 'Comentário interno salvo — o cliente não vê.' : 'Comentário enviado.';
    }

    /** @return Collection<int, Comment> */
    public function commentsFor(Post $post): Collection
    {
        return $post->comments()->visibleTo(auth()->user())->with('user')->oldest()->get();
    }

    public function canDecide(Post $post): bool
    {
        return auth()->user()?->can('decide', $post) === true
            && $post->status === PostStatus::AwaitingClient;
    }

    public function isClientAdmin(): bool
    {
        return auth()->user()?->hasRole(RoleName::ClientAdmin->value) === true;
    }

    public function render(): View
    {
        return view('livewire.approvals.approval-queue');
    }
}
