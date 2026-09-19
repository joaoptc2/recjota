<?php

declare(strict_types=1);

namespace App\Livewire\Posts;

use App\Actions\Approvals\SendApprovalRequest;
use App\Actions\Posts\CreatePost;
use App\Actions\Posts\UpdatePost;
use App\Models\Client;
use App\Models\HashtagSet;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Media\PlatformMediaValidator;
use App\Support\DataObjects\PostData;
use App\Support\Display;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Throwable;

/**
 * Composer de postagem (Seção 6.5): editor à esquerda, preview fiel à direita.
 *
 * Toda validação que a plataforma faria depois é feita aqui, antes — é a
 * diferença entre corrigir com a pessoa olhando a tela e falhar num job de
 * madrugada.
 */
class PostComposer extends Component
{
    public Client $client;

    public ?Post $post = null;

    // --------------------------------------------------------------- campos
    public string $type = 'feed_image';

    public string $caption = '';

    public string $firstComment = '';

    public ?int $socialAccountId = null;

    public ?int $campaignId = null;

    /** Digitado no fuso do cliente; convertido para UTC só ao salvar (R2). */
    public string $scheduledAt = '';

    /** @var array<int, int> IDs de mídia na ordem do carrossel. */
    public array $mediaIds = [];

    /** @var array<int, string> */
    public array $altTexts = [];

    /** Publicar a mesma peça em várias contas do cliente (Seção 6.5). */
    public array $extraAccountIds = [];

    public bool $showMediaPicker = false;

    public ?string $savedAt = null;

    public function mount(Client $client, ?Post $post = null): void
    {
        $this->client = $client;

        // O Livewire injeta um Post vazio quando o parâmetro não é passado;
        // só um registro existente conta como edição.
        $this->post = $post?->exists ? $post : null;

        if ($this->post !== null) {
            $this->type = $this->post->type->value;
            $this->caption = (string) $this->post->caption;
            $this->firstComment = (string) $this->post->first_comment;
            $this->socialAccountId = $this->post->social_account_id;
            $this->campaignId = $this->post->campaign_id;
            $this->scheduledAt = $this->post->scheduled_at
                ? Display::carbon($this->post->scheduled_at, $client)->format('Y-m-d\TH:i')
                : '';
            $this->mediaIds = $this->post->postMedia->sortBy('position')->pluck('media_asset_id')->all();
            $this->altTexts = $this->post->postMedia->pluck('alt_text', 'media_asset_id')->filter()->all();

            return;
        }

        $this->socialAccountId = $client->socialAccounts()->connected()->value('id');
    }

    // ------------------------------------------------------------ derivados

    #[Computed]
    public function postType(): PostType
    {
        return PostType::tryFrom($this->type) ?? PostType::FeedImage;
    }

    #[Computed]
    public function accounts()
    {
        return $this->client->socialAccounts()->orderBy('username')->get();
    }

    #[Computed]
    public function campaigns()
    {
        return $this->client->campaigns()->orderByDesc('starts_at')->get();
    }

    #[Computed]
    public function hashtagSets()
    {
        return HashtagSet::where('client_id', $this->client->getKey())->orderBy('name')->get();
    }

    #[Computed]
    public function selectedMedia()
    {
        if ($this->mediaIds === []) {
            return collect();
        }

        $encontradas = MediaAsset::whereIn('id', $this->mediaIds)->get()->keyBy('id');

        // Preserva a ordem escolhida — é ela que define a sequência do carrossel.
        return collect($this->mediaIds)
            ->map(fn (int $id) => $encontradas->get($id))
            ->filter()
            ->values();
    }

    #[Computed]
    public function captionLength(): int
    {
        return mb_strlen($this->caption);
    }

    #[Computed]
    public function hashtagCount(): int
    {
        return preg_match_all('/(?<!\w)#[\p{L}\p{N}_]+/u', $this->caption);
    }

    #[Computed]
    public function mentionCount(): int
    {
        return preg_match_all('/(?<!\w)@[A-Za-z0-9._]+/', $this->caption);
    }

    /** O feed corta a legenda em ~125 caracteres; o resto vira "... mais". */
    #[Computed]
    public function captionPreviewTruncated(): bool
    {
        return $this->captionLength() > config('agency.limits.caption_truncate_at');
    }

    #[Computed]
    public function scheduleHint(): ?string
    {
        if ($this->scheduledAt === '') {
            return null;
        }

        try {
            return Display::localWithUtc(Display::toUtc($this->scheduledAt, $this->client), $this->client);
        } catch (Throwable) {
            return null;
        }
    }

    /** Erros que impedem o envio para aprovação (Seção 6.5). */
    #[Computed]
    public function blockingIssues(): array
    {
        $erros = [];
        $limites = config('agency.limits');
        $tipo = $this->postType();

        if ($this->selectedMedia()->isEmpty()) {
            $erros[] = 'Adicione ao menos uma mídia: o Instagram não publica post só com texto.';
        }

        if ($this->selectedMedia()->count() > $tipo->maxMediaItems()) {
            $erros[] = sprintf('%s aceita no máximo %d itens.', $tipo->label(), $tipo->maxMediaItems());
        }

        if ($this->captionLength() > $limites['caption_max_chars']) {
            $erros[] = sprintf('A legenda tem %d caracteres; o limite é %d.', $this->captionLength(), $limites['caption_max_chars']);
        }

        if ($this->hashtagCount() > $limites['hashtags_max']) {
            $erros[] = sprintf('São %d hashtags; o Instagram ignora tudo acima de %d.', $this->hashtagCount(), $limites['hashtags_max']);
        }

        if ($this->mentionCount() > $limites['mentions_max']) {
            $erros[] = sprintf('São %d menções; o limite é %d.', $this->mentionCount(), $limites['mentions_max']);
        }

        $conta = $this->socialAccountId !== null
            ? SocialAccount::find($this->socialAccountId)
            : null;

        if ($tipo->requiresBusinessAccount() && $conta !== null && ! $conta->canPublishStories()) {
            $erros[] = 'Stories por API exigem conta Business. Esta conta é Creator.';
        }

        if ($this->scheduledAt !== '') {
            try {
                if (Display::toUtc($this->scheduledAt, $this->client)->isPast()) {
                    $erros[] = 'A data de agendamento já passou.';
                }
            } catch (Throwable) {
                $erros[] = 'A data de agendamento não é válida.';
            }
        }

        $validador = app(PlatformMediaValidator::class);

        foreach ($this->selectedMedia() as $asset) {
            $erros = [...$erros, ...$validador->forAsset($asset, $tipo)->errors];
        }

        return $erros;
    }

    #[Computed]
    public function warnings(): array
    {
        $avisos = [];
        $validador = app(PlatformMediaValidator::class);

        foreach ($this->selectedMedia() as $asset) {
            $avisos = [...$avisos, ...$validador->forAsset($asset, $this->postType())->warnings];
        }

        return $avisos;
    }

    // ---------------------------------------------------------------- ações

    public function toggleMedia(int $mediaId): void
    {
        $indice = array_search($mediaId, $this->mediaIds, true);

        if ($indice !== false) {
            unset($this->mediaIds[$indice]);
            $this->mediaIds = array_values($this->mediaIds);

            return;
        }

        if (count($this->mediaIds) >= $this->postType()->maxMediaItems()) {
            $this->addError('media', sprintf(
                '%s aceita no máximo %d itens. Remova um antes de adicionar outro.',
                $this->postType()->label(),
                $this->postType()->maxMediaItems(),
            ));

            return;
        }

        $this->mediaIds[] = $mediaId;
        $this->resetErrorBag('media');
    }

    public function moveMedia(int $de, int $para): void
    {
        if (! isset($this->mediaIds[$de]) || ! isset($this->mediaIds[$para])) {
            return;
        }

        $item = array_splice($this->mediaIds, $de, 1);
        array_splice($this->mediaIds, $para, 0, $item);
    }

    public function applyHashtagSet(int $setId): void
    {
        $conjunto = HashtagSet::find($setId);

        if ($conjunto === null) {
            return;
        }

        $this->caption = trim($this->caption."\n\n".$conjunto->asText());
    }

    /** Salvamento automático a cada 20s (Seção 6.5). */
    public function autosave(): void
    {
        if ($this->post !== null && ! $this->post->status->isEditable()) {
            return;
        }

        try {
            $this->persist();
            $this->savedAt = now()->setTimezone($this->client->displayTimezone())->format('H:i:s');
        } catch (Throwable) {
            // Autosave nunca interrompe quem está escrevendo.
        }
    }

    public function save(): void
    {
        $this->persist();

        $this->dispatch('post-salvo');
        session()->flash('status', 'Rascunho salvo.');

        $this->redirectRoute('painel.posts.edit', $this->post, navigate: true);
    }

    public function sendForApproval(): void
    {
        if ($this->blockingIssues() !== []) {
            throw ValidationException::withMessages([
                'caption' => 'Corrija os pontos listados antes de enviar para aprovação.',
            ]);
        }

        $this->persist();

        // Revisão interna obrigatória: o post para na equipe antes de ir ao
        // cliente, e nenhum e-mail sai ainda (Seção 6.6).
        if ($this->client->settings?->internal_review_required) {
            $this->post->transitionTo(PostStatus::InReview);
            $this->post->save();

            session()->flash('status', 'Enviado para revisão interna.');
            $this->redirectRoute('painel.posts.show', $this->post, navigate: true);

            return;
        }

        $links = app(SendApprovalRequest::class)($this->post, auth()->id());

        session()->flash('status', $links[0]['email'] !== null
            ? sprintf('Enviado para o cliente aprovar — %d e-mail(s) a caminho.', count($links))
            : 'Pedido aberto. Nenhum aprovador cadastrado: copie o link em Aprovações para enviar à mão.');

        $this->redirectRoute('painel.posts.show', $this->post, navigate: true);
    }

    private function persist(): void
    {
        $this->validate([
            'type' => ['required'],
            'caption' => ['nullable', 'string', 'max:5000'],
            'firstComment' => ['nullable', 'string', 'max:2200'],
            'scheduledAt' => ['nullable', 'date'],
        ], [], [
            'caption' => 'legenda',
            'scheduledAt' => 'data de agendamento',
        ]);

        $dados = new PostData(
            clientId: $this->client->getKey(),
            type: $this->postType(),
            caption: $this->caption !== '' ? $this->caption : null,
            firstComment: $this->firstComment !== '' ? $this->firstComment : null,
            socialAccountId: $this->socialAccountId,
            campaignId: $this->campaignId,
            scheduledAt: $this->scheduledAt !== '' ? Display::toUtc($this->scheduledAt, $this->client) : null,
            media: $this->mediaPayload(),
            createdBy: auth()->id(),
        );

        if ($this->post === null) {
            $this->authorize('create', Post::class);
            $this->post = app(CreatePost::class)($dados);
            $this->replicateForExtraAccounts($dados);

            return;
        }

        $this->authorize('update', $this->post);
        $this->post = app(UpdatePost::class)($this->post, $dados);
    }

    /** @return array<int, array{media_asset_id: int, alt_text: ?string}> */
    private function mediaPayload(): array
    {
        return collect($this->mediaIds)
            ->map(fn (int $id) => [
                'media_asset_id' => $id,
                'alt_text' => $this->altTexts[$id] ?? null,
            ])
            ->all();
    }

    /**
     * Compor uma vez e publicar em várias contas gera posts independentes,
     * irmãos entre si — cada um com seu próprio ciclo de aprovação e falha.
     */
    private function replicateForExtraAccounts(PostData $dados): void
    {
        foreach (array_filter($this->extraAccountIds) as $contaId) {
            if ((int) $contaId === (int) $this->socialAccountId) {
                continue;
            }

            $irmao = app(CreatePost::class)(new PostData(
                clientId: $dados->clientId,
                type: $dados->type,
                caption: $dados->caption,
                firstComment: $dados->firstComment,
                socialAccountId: (int) $contaId,
                campaignId: $dados->campaignId,
                scheduledAt: $dados->scheduledAt,
                media: $dados->media,
                createdBy: $dados->createdBy,
            ));

            $irmao->forceFill(['sibling_group_id' => $this->post->getKey()])->save();
        }

        $this->extraAccountIds = [];
    }

    public function render(): View
    {
        return view('livewire.posts.post-composer');
    }
}
