<div>
    <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-48 flex-1">
            <label for="busca-{{ $this->getId() }}" class="label">Buscar</label>
            <input id="busca-{{ $this->getId() }}" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="nome do arquivo" class="input">
        </div>

        <div>
            <label for="tipo-{{ $this->getId() }}" class="label">Tipo</label>
            <select id="tipo-{{ $this->getId() }}" wire:model.live="filterType" class="input">
                <option value="todos">Todos</option>
                <option value="imagem">Imagens</option>
                <option value="video">Vídeos</option>
            </select>
        </div>

        <div>
            <label for="upload-{{ $this->getId() }}" class="label">Enviar arquivos</label>
            <input id="upload-{{ $this->getId() }}" type="file" wire:model="uploads" multiple
                   accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime"
                   class="block w-full text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-brand-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white">
        </div>
    </div>

    <div wire:loading wire:target="uploads" class="mt-3 text-sm text-slate-500 dark:text-slate-400">
        Enviando e gerando miniaturas…
    </div>

    @can('create', \App\Models\MediaAsset::class)
        <div class="mt-3">
            <livewire:media.cloud-picker :client="$client" :folder-id="$folderId" :key="'nuvem-'.$client->getKey()" />
        </div>
    @endcan

    @error('uploads')<p class="mt-2 text-sm text-rose-600">{{ $message }}</p>@enderror

    @if ($this->assets()->isEmpty())
        <div class="card mt-4">
            <x-empty-state title="Nenhum arquivo aqui ainda">
                Envie imagens e vídeos por este formulário ou escolha do Google Drive / OneDrive do cliente.
                O original fica fora da área pública; a interface usa apenas miniaturas.
            </x-empty-state>
        </div>
    @else
        <ul class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-4 lg:grid-cols-6">
            @foreach ($this->assets() as $asset)
                @php $escolhida = in_array($asset->id, $selected, true); @endphp
                <li class="group relative">
                    <button type="button"
                            @if ($picker) wire:click="toggle({{ $asset->id }})" @endif
                            class="block w-full overflow-hidden rounded-lg border-2 transition {{ $escolhida ? 'border-brand-600' : 'border-transparent hover:border-slate-300 dark:hover:border-slate-600' }}">
                        <span class="relative block aspect-square bg-slate-100 dark:bg-slate-800">
                            <img src="{{ $asset->thumbUrl() }}" alt="{{ $asset->filename }}" loading="lazy"
                                 class="absolute inset-0 size-full object-cover">
                            @if ($asset->isVideo())
                                <span class="absolute bottom-1 left-1 rounded bg-black/60 px-1.5 text-[10px] text-white">vídeo</span>
                            @endif
                            @if ($escolhida)
                                <span class="absolute right-1 top-1 flex size-5 items-center justify-center rounded-full bg-brand-600 text-[11px] font-bold text-white">✓</span>
                            @endif
                        </span>
                    </button>

                    <p class="mt-1 truncate text-[11px] text-slate-500 dark:text-slate-400" title="{{ $asset->filename }}">
                        {{ $asset->filename }}
                    </p>
                    <p class="text-[10px] text-slate-400">{{ $asset->dimensionsLabel() }} · {{ $asset->humanSize() }}</p>

                    @unless ($picker)
                        <button type="button" wire:click="delete({{ $asset->id }})"
                                wire:confirm="Excluir {{ $asset->filename }}? Isto não pode ser desfeito."
                                class="absolute right-1 top-1 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white opacity-0 transition group-hover:opacity-100 focus:opacity-100">
                            excluir
                        </button>
                    @endunless
                </li>
            @endforeach
        </ul>

        <div class="mt-4">{{ $this->assets()->links() }}</div>
    @endif
</div>
