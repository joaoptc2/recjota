<div>
    @if ($feedback)
        <x-alert type="success" class="mb-4">{{ $feedback }}</x-alert>
    @endif

    @if ($linksEmitidos)
        <div class="card mb-4 p-4">
            <h2 class="text-sm font-semibold">Links de aprovação emitidos</h2>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                Cada link é pessoal e deixa de valer se o post for editado.
            </p>

            <ul class="mt-3 space-y-2">
                @foreach ($linksEmitidos as $emitido)
                    <li class="flex flex-wrap items-center gap-2 text-sm">
                        <span class="text-slate-600 dark:text-slate-300">
                            {{ $emitido['nome'] ?? $emitido['email'] ?? 'Link avulso' }}
                        </span>
                        <input type="text" readonly value="{{ $emitido['url'] }}"
                               onclick="this.select()" aria-label="Link de aprovação"
                               class="input !w-auto min-w-0 flex-1 !py-1.5 !text-xs">
                        <a href="{{ \App\Support\Approvals\WhatsAppMessage::forApproval($this->posts()->firstWhere('id', $postEmFoco) ?? $this->posts()->first(), $emitido['url']) }}"
                           target="_blank" rel="noopener" class="btn-secondary !px-3 !py-1.5 !text-xs">WhatsApp</a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-1 rounded-lg border border-slate-200 p-1 dark:border-slate-700">
            @foreach (['pendentes' => 'Pendentes', 'ajustes' => 'Com ajustes', 'reprovados' => 'Reprovados', 'prontos' => 'Aprovados'] as $chave => $rotulo)
                <button type="button" wire:click="$set('filtro', '{{ $chave }}')"
                        class="rounded px-2.5 py-1 text-xs font-medium transition
                               {{ $filtro === $chave ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}">
                    {{ $rotulo }}
                </button>
            @endforeach
        </div>

        @if ($clientSide && $this->isClientAdmin() && $filtro === 'pendentes' && $this->posts()->isNotEmpty())
            <button type="button" wire:click="approveAll"
                    wire:confirm="Aprovar todos os posts pendentes?"
                    class="btn-primary !py-2">Aprovar todos</button>
        @endif
    </div>

    @if ($this->atrasados()->isNotEmpty() && ! $clientSide)
        <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm dark:border-amber-900 dark:bg-amber-950/60">
            <p class="font-semibold text-amber-900 dark:text-amber-200">
                {{ $this->atrasados()->count() }} post(s) passaram do prazo de decisão
            </p>
            <p class="mt-0.5 text-amber-800 dark:text-amber-300">
                Reenviar o link costuma resolver mais rápido do que esperar.
            </p>
        </div>
    @endif

    <div class="mt-4 space-y-4">
        @forelse ($this->posts() as $post)
            @php
                $pendente = $post->approvals->firstWhere('status', \App\Support\Enums\ApprovalStatus::Pending);
                $capa = $post->postMedia->first()?->mediaAsset;
            @endphp

            <article class="card overflow-hidden">
                <div class="flex flex-col gap-3 p-4 sm:flex-row">
                    <div class="flex gap-3">
                        <span class="size-20 shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                            @if ($capa)
                                <img src="{{ $capa->thumbUrl() }}" alt="" class="size-full object-cover">
                            @endif
                        </span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                            @unless ($clientSide)
                                <span class="text-xs text-slate-500 dark:text-slate-400">{{ $post->client->name }}</span>
                            @endunless
                            <span class="text-xs text-slate-400">v{{ $post->current_version }}</span>
                            @if ($pendente?->isOverdue())
                                <span class="text-xs font-semibold text-rose-600 dark:text-rose-400">prazo vencido</span>
                            @endif
                        </div>

                        <p class="mt-1.5 text-sm">{{ \Illuminate\Support\Str::limit($post->caption ?: 'Sem legenda.', 160) }}</p>

                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            {{ $post->type->label() }} ·
                            {{ $post->scheduled_at ? display_datetime($post->scheduled_at, $post->client) : 'sem data' }}
                            @if ($pendente?->due_at)
                                · responder até {{ display_datetime($pendente->due_at, $post->client) }}
                            @endif
                        </p>

                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="focus({{ $post->id }})" class="btn-secondary !px-3 !py-1.5 !text-xs">
                                {{ $postEmFoco === $post->id ? 'Fechar' : 'Abrir' }}
                            </button>

                            @unless ($clientSide)
                                <a href="{{ route('painel.posts.show', $post) }}" class="btn-secondary !px-3 !py-1.5 !text-xs">Ver post</a>

                                @can('requestApproval', $post)
                                    @if (in_array($post->status, [\App\Support\Enums\PostStatus::AwaitingClient, \App\Support\Enums\PostStatus::InReview, \App\Support\Enums\PostStatus::ChangesRequested], true))
                                        <button type="button" wire:click="send({{ $post->id }})" class="btn-primary !px-3 !py-1.5 !text-xs">
                                            {{ $pendente ? 'Reenviar link' : 'Enviar para aprovação' }}
                                        </button>
                                    @endif
                                @endcan
                            @endunless

                            @if ($this->canDecide($post))
                                <button type="button" wire:click="decide({{ $post->id }}, 'approved')" class="btn-primary !px-3 !py-1.5 !text-xs">
                                    Aprovar
                                </button>
                            @endif
                        </div>
                    </div>
                </div>

                @if ($postEmFoco === $post->id)
                    <div class="border-t border-slate-200 bg-slate-50 p-4 dark:border-slate-800 dark:bg-slate-900/60">
                        <p class="whitespace-pre-line text-sm">{{ $post->caption }}</p>

                        @if ($this->canDecide($post))
                            <div class="mt-4">
                                <label for="nota-{{ $post->id }}" class="label">Comentário da decisão</label>
                                <textarea id="nota-{{ $post->id }}" wire:model="nota" rows="2" class="input"
                                          placeholder="Obrigatório para reprovar ou pedir ajustes."></textarea>
                                @error('nota')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror

                                <div class="mt-2 flex flex-wrap gap-2">
                                    <button type="button" wire:click="decide({{ $post->id }}, 'changes_requested')" class="btn-secondary !py-2 !text-xs">
                                        Solicitar ajustes
                                    </button>
                                    <button type="button" wire:click="decide({{ $post->id }}, 'rejected')" class="btn-secondary !py-2 !text-xs !text-rose-700 dark:!text-rose-300">
                                        Reprovar
                                    </button>
                                </div>
                            </div>
                        @endif

                        <div class="mt-4">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Conversa</h3>

                            @forelse ($this->commentsFor($post) as $comentario)
                                <div class="mt-2 rounded-lg bg-white p-3 dark:bg-slate-900">
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-medium">{{ $comentario->authorName() }}</p>
                                        @if ($comentario->is_internal)
                                            <x-badge classes="bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30">interno</x-badge>
                                        @endif
                                        <span class="text-xs text-slate-400">v{{ $comentario->post_version }}</span>
                                    </div>
                                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $comentario->body }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ display_datetime($comentario->created_at, $post->client) }}</p>
                                </div>
                            @empty
                                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum comentário ainda.</p>
                            @endforelse

                            <div class="mt-3">
                                <label for="coment-{{ $post->id }}" class="sr-only">Novo comentário</label>
                                <textarea id="coment-{{ $post->id }}" wire:model="comentario" rows="2" class="input"
                                          placeholder="Escrever um comentário"></textarea>
                                @error('comentario')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror

                                <div class="mt-2 flex flex-wrap items-center gap-3">
                                    <button type="button" wire:click="comment({{ $post->id }})" class="btn-secondary !py-2 !text-xs">Comentar</button>

                                    @can('createInternal', \App\Models\Comment::class)
                                        <label class="flex items-center gap-2 text-xs text-slate-600 dark:text-slate-300">
                                            <input type="checkbox" wire:model="comentarioInterno"
                                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                                            Interno — o cliente não vê
                                        </label>
                                    @endcan
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </article>
        @empty
            <div class="card">
                <x-empty-state title="Nada nesta lista">
                    @if ($filtro === 'pendentes')
                        Quando um post for enviado para aprovação, ele aparece aqui com o prazo de decisão.
                    @else
                        Troque o filtro acima para ver outras situações.
                    @endif
                </x-empty-state>
            </div>
        @endforelse
    </div>
</div>
