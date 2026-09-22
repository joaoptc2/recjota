@php
    $provedorAtivo = $this->activeProvider();
    $conexao = $this->connection();
    $google = \App\Support\Enums\CloudProvider::GoogleDrive;
    $onedrive = \App\Support\Enums\CloudProvider::OneDrive;
    $podeConectar = auth()->user()?->can('create', \App\Models\CloudConnection::class);
@endphp

<div>
    <div class="flex flex-wrap items-center gap-2">
        @foreach ([$google, $onedrive] as $provedor)
            @php $conexoes = $this->connectionsFor($provedor); @endphp
            @if ($conexoes->isNotEmpty())
                <button type="button" wire:click="{{ $provedorAtivo === $provedor ? 'close' : 'open(\''.$provedor->value.'\')' }}"
                        class="btn-secondary !px-3 !py-1.5 !text-xs {{ $provedorAtivo === $provedor ? 'ring-2 ring-brand-500' : '' }}"
                        aria-expanded="{{ $provedorAtivo === $provedor ? 'true' : 'false' }}">
                    {{ $provedorAtivo === $provedor ? 'Fechar '.$provedor->label() : 'Escolher do '.$provedor->label() }}
                </button>
            @elseif ($podeConectar)
                <a href="{{ route('painel.integrations.cloud.connect', ['provider' => $provedor->slug(), 'client' => $client]) }}"
                   class="inline-flex items-center rounded-lg border border-dashed border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800">
                    Conectar {{ $provedor->label() }}
                </a>
            @endif
        @endforeach
    </div>

    @if ($provedorAtivo !== null)
        <div class="mt-3 rounded-lg border border-slate-200 p-3 dark:border-slate-700"
             @if ($provedorAtivo === $google && $conexao !== null && ! $conexao->needsReconnection())
                 x-data="googlePicker({
                     tokenUrl: @js(route('painel.integrations.cloud.token', $conexao)),
                     onPick: (id) => $wire.import(id),
                 })"
             @endif>
            <div class="flex flex-wrap items-end gap-3">
                @if ($this->connectionsFor($provedorAtivo)->count() > 1)
                    <div>
                        <label for="conexao-{{ $this->getId() }}" class="label">Conta</label>
                        <select id="conexao-{{ $this->getId() }}" wire:model.live="connectionId" class="input !w-auto">
                            @foreach ($this->connectionsFor($provedorAtivo) as $opcao)
                                <option value="{{ $opcao->getKey() }}">{{ $opcao->label() }}{{ $opcao->needsReconnection() ? ' (reconectar)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                @else
                    <p class="text-xs text-slate-500 dark:text-slate-400">Conta: <span class="font-medium text-slate-700 dark:text-slate-200">{{ $conexao?->label() }}</span></p>
                @endif

                @if ($provedorAtivo === $google && $conexao !== null && ! $conexao->needsReconnection())
                    <button type="button" x-on:click="openPicker()" x-bind:disabled="loading" class="btn-primary !px-3 !py-1.5 !text-xs disabled:opacity-60">
                        <span x-show="!loading">Abrir o Google Drive</span>
                        <span x-show="loading" x-cloak>Abrindo…</span>
                    </button>
                    <p class="text-xs text-slate-500 dark:text-slate-400" x-show="error" x-text="error" x-cloak></p>
                @endif
            </div>

            @if ($conexao !== null && $conexao->needsReconnection())
                <div class="mt-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                    <p>{{ $conexao->last_error ?? 'Esta conexão precisa ser refeita.' }}</p>
                    @if ($podeConectar)
                        <a href="{{ route('painel.integrations.cloud.connect', ['provider' => $provedorAtivo->slug(), 'client' => $client]) }}" class="mt-1 inline-block font-semibold underline">Reconectar {{ $provedorAtivo->label() }}</a>
                    @endif
                </div>
            @else
                @if ($provedorAtivo === $google)
                    <p class="mt-3 text-xs text-slate-500 dark:text-slate-400">
                        O Google só mostra ao sistema os arquivos que você escolher no seletor. A lista abaixo é o que já foi escolhido antes.
                    </p>
                @endif

                <nav class="mt-3 flex flex-wrap items-center gap-1 text-xs" aria-label="Pastas">
                    @foreach ($breadcrumbs as $i => $nivel)
                        @if ($i > 0)<span class="text-slate-400">/</span>@endif
                        @if ($i === count($breadcrumbs) - 1)
                            <span class="font-medium">{{ $nivel['name'] }}</span>
                        @else
                            <button type="button" wire:click="up({{ $i }})" class="text-brand-700 hover:underline dark:text-brand-300">{{ $nivel['name'] }}</button>
                        @endif
                    @endforeach
                </nav>

                @error('cloud')<p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p>@enderror
                @if ($feedback !== '')<p class="mt-2 text-sm text-emerald-700 dark:text-emerald-300">{{ $feedback }}</p>@endif

                <div wire:loading wire:target="browse,up,import,connectionId,open" class="mt-2 text-xs text-slate-500 dark:text-slate-400">Carregando…</div>

                @php $itens = $this->items(); @endphp
                @if ($itens === [])
                    @if ($errors->has('cloud'))
                    @elseif ($provedorAtivo === $google)
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Nenhum arquivo escolhido ainda. Clique em "Abrir o Google Drive" para selecionar imagens ou vídeos.</p>
                    @else
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Esta pasta está vazia.</p>
                    @endif
                @else
                    <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-100 dark:divide-slate-800 dark:border-slate-800">
                        @foreach ($itens as $item)
                            <li class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="shrink-0 text-slate-400" aria-hidden="true">{{ $item->isFolder ? '📁' : ($item->isVideo() ? '🎬' : ($item->isImage() ? '🖼️' : '📄')) }}</span>
                                    @if ($item->isFolder)
                                        <button type="button" wire:click="browse(@js($item->id), @js($item->name))" class="truncate text-left font-medium hover:underline">{{ $item->name }}</button>
                                    @else
                                        <span class="truncate {{ $item->isMedia() ? '' : 'text-slate-400' }}" title="{{ $item->name }}">{{ $item->name }}</span>
                                        @if ($item->sizeBytes !== null)
                                            <span class="shrink-0 text-xs text-slate-400">{{ $item->sizeBytes >= 1048576 ? number_format($item->sizeBytes / 1048576, 1, ',', '.').' MB' : number_format($item->sizeBytes / 1024, 0, ',', '.').' KB' }}</span>
                                        @endif
                                    @endif
                                </div>
                                @if (! $item->isFolder)
                                    @if ($item->isMedia())
                                        <button type="button" wire:click="import(@js($item->id))" wire:loading.attr="disabled" class="btn-secondary !px-2.5 !py-1 !text-xs">Importar</button>
                                    @else
                                        <span class="text-xs text-slate-400">não é mídia</span>
                                    @endif
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </div>
    @endif
</div>
