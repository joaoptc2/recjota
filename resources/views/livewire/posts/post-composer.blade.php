@php
    $limites = config('agency.limits');
    $tipo = $this->postType();
@endphp

{{-- Abaixo de 1024px o preview vai para baixo do editor (Seção 9.2). --}}
<div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]" wire:poll.20s="autosave">
    <div class="space-y-4">
        @if ($this->blockingIssues())
            <div class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm dark:border-rose-900 dark:bg-rose-950/60">
                <p class="font-semibold text-rose-800 dark:text-rose-200">Resolva antes de enviar para aprovação</p>
                <ul class="mt-2 space-y-1 text-rose-700 dark:text-rose-300">
                    @foreach ($this->blockingIssues() as $erro)
                        <li>· {{ $erro }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @foreach ($this->warnings() as $aviso)
            <x-alert type="info">{{ $aviso }}</x-alert>
        @endforeach

        <section class="card p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="type" class="label">Tipo</label>
                    <select id="type" wire:model.live="type" class="input">
                        @foreach (\App\Support\Enums\PostType::cases() as $opcao)
                            <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="socialAccountId" class="label">Conta</label>
                    <select id="socialAccountId" wire:model.live="socialAccountId" class="input">
                        <option value="">A definir</option>
                        @foreach ($this->accounts() as $conta)
                            <option value="{{ $conta->id }}">{{ $conta->handle() }} · {{ $conta->account_type?->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            @if ($this->accounts()->count() > 1 && $post === null)
                <fieldset class="mt-4">
                    <legend class="label">Publicar também em</legend>
                    <p class="mb-2 text-xs text-slate-500 dark:text-slate-400">
                        Cada conta recebe um post independente, com seu próprio ciclo de aprovação.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        @foreach ($this->accounts() as $conta)
                            @if ($conta->id !== $socialAccountId)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model="extraAccountIds" value="{{ $conta->id }}"
                                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                                    {{ $conta->handle() }}
                                </label>
                            @endif
                        @endforeach
                    </div>
                </fieldset>
            @endif
        </section>

        <section class="card p-4">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold">Mídia</h2>
                <span class="text-xs text-slate-500 dark:text-slate-400">
                    {{ $this->selectedMedia()->count() }}/{{ $tipo->maxMediaItems() }}
                </span>
            </div>

            @error('media')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror

            @if ($this->selectedMedia()->isNotEmpty())
                <ul class="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-5">
                    @foreach ($this->selectedMedia() as $i => $asset)
                        <li class="group relative aspect-square overflow-hidden rounded-lg border border-slate-200 dark:border-slate-700">
                            <img src="{{ $asset->thumbUrl() }}" alt="" class="size-full object-cover">
                            <span class="absolute left-1 top-1 rounded bg-black/60 px-1.5 text-[10px] font-semibold text-white">{{ $i + 1 }}</span>
                            <div class="absolute inset-x-0 bottom-0 flex justify-between bg-black/50 opacity-0 transition group-hover:opacity-100 focus-within:opacity-100">
                                <button type="button" wire:click="moveMedia({{ $i }}, {{ max(0, $i - 1) }})"
                                        @disabled($i === 0) class="px-1.5 py-1 text-xs text-white disabled:opacity-30" aria-label="Mover para a esquerda">←</button>
                                <button type="button" wire:click="toggleMedia({{ $asset->id }})"
                                        class="px-1.5 py-1 text-xs text-white" aria-label="Remover">✕</button>
                                <button type="button" wire:click="moveMedia({{ $i }}, {{ min($this->selectedMedia()->count() - 1, $i + 1) }})"
                                        @disabled($i === $this->selectedMedia()->count() - 1) class="px-1.5 py-1 text-xs text-white disabled:opacity-30" aria-label="Mover para a direita">→</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            <button type="button" wire:click="$toggle('showMediaPicker')" class="btn-secondary mt-3">
                {{ $showMediaPicker ? 'Fechar biblioteca' : 'Escolher da biblioteca' }}
            </button>

            @if ($showMediaPicker)
                <div class="mt-4 rounded-lg border border-slate-200 p-3 dark:border-slate-700">
                    <livewire:media.media-library :client="$client" :picker="true" :selected="$mediaIds" />
                </div>
            @endif
        </section>

        <section class="card p-4">
            <label for="caption" class="label">Legenda</label>
            <textarea id="caption" wire:model.live.debounce.400ms="caption" rows="7" class="input font-normal"
                      placeholder="Escreva a legenda. Hashtags contam; emojis também."></textarea>

            {{-- Contadores bloqueantes (Seção 7.1.5). --}}
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                <span class="{{ $this->captionLength() > $limites['caption_max_chars'] ? 'font-semibold text-rose-600' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $this->captionLength() }}/{{ $limites['caption_max_chars'] }} caracteres
                </span>
                <span class="{{ $this->hashtagCount() > $limites['hashtags_max'] ? 'font-semibold text-rose-600' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $this->hashtagCount() }}/{{ $limites['hashtags_max'] }} hashtags
                </span>
                <span class="{{ $this->mentionCount() > $limites['mentions_max'] ? 'font-semibold text-rose-600' : 'text-slate-500 dark:text-slate-400' }}">
                    {{ $this->mentionCount() }}/{{ $limites['mentions_max'] }} menções
                </span>
                @if ($this->captionPreviewTruncated())
                    <span class="text-amber-600 dark:text-amber-400">
                        corta em {{ $limites['caption_truncate_at'] }} caracteres no feed
                    </span>
                @endif
            </div>

            @if ($this->hashtagSets()->isNotEmpty())
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($this->hashtagSets() as $conjunto)
                        <button type="button" wire:click="applyHashtagSet({{ $conjunto->id }})" class="btn-secondary !px-3 !py-1.5 !text-xs">
                            + {{ $conjunto->name }}
                        </button>
                    @endforeach
                </div>
            @endif

            @if ($tipo->acceptsCaption())
                <label for="firstComment" class="label mt-5">Primeiro comentário</label>
                <textarea id="firstComment" wire:model.blur="firstComment" rows="2" class="input"
                          placeholder="Publicado logo após o post — bom lugar para o bloco de hashtags."></textarea>
            @endif
        </section>

        <section class="card p-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="scheduledAt" class="label">Agendar para</label>
                    <input id="scheduledAt" type="datetime-local" wire:model.live="scheduledAt" class="input">
                    {{-- O texto auxiliar com o UTC elimina a classe de bug mais
                         comum em agendamento (Seção 8.4). --}}
                    @if ($this->scheduleHint())
                        <p class="hint mt-1.5 text-xs text-slate-500 dark:text-slate-400">{{ $this->scheduleHint() }}</p>
                    @endif
                </div>

                <div>
                    <label for="campaignId" class="label">Campanha</label>
                    <select id="campaignId" wire:model="campaignId" class="input">
                        <option value="">Sem campanha</option>
                        @foreach ($this->campaigns() as $campanha)
                            <option value="{{ $campanha->id }}">{{ $campanha->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <button type="button" wire:click="save" class="btn-secondary">Salvar rascunho</button>
            <button type="button" wire:click="sendForApproval" class="btn-primary" @disabled($this->blockingIssues() !== [])>
                Enviar para aprovação
            </button>
            @if ($savedAt)
                <span class="text-xs text-slate-400">salvo automaticamente às {{ $savedAt }}</span>
            @endif
        </div>
    </div>

    <aside class="lg:sticky lg:top-6 lg:self-start">
        <p class="mb-3 text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Como vai aparecer</p>
        @include('partials.instagram-preview', [
            'type' => $tipo,
            'account' => $socialAccountId ? $this->accounts()->firstWhere('id', $socialAccountId) : null,
            'media' => $this->selectedMedia(),
            'caption' => $caption,
            'truncated' => $this->captionPreviewTruncated(),
        ])
    </aside>
</div>
