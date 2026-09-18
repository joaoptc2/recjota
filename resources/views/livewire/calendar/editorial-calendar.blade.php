@php
    $tz = $client?->displayTimezone() ?? config('agency.default_timezone');
    $hoje = \Illuminate\Support\Carbon::now($tz)->format('Y-m-d');
    $ancoraMes = \Illuminate\Support\Carbon::parse($anchor, $tz)->format('Y-m');
@endphp

<div x-data="{ arrastando: null }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="move(-1)" class="btn-secondary !px-3 !py-1.5" aria-label="Período anterior">←</button>
            <button type="button" wire:click="today" class="btn-secondary !px-3 !py-1.5 !text-xs">Hoje</button>
            <button type="button" wire:click="move(1)" class="btn-secondary !px-3 !py-1.5" aria-label="Próximo período">→</button>
            <p class="ml-1 text-sm font-semibold">{{ $this->periodLabel() }}</p>
        </div>

        {{-- Abaixo de 768px o mês não é renderizado: vira lista (Seção 6.3). --}}
        <div class="flex gap-1 rounded-lg border border-slate-200 p-1 dark:border-slate-700" role="tablist">
            @foreach (['mes' => 'Mês', 'semana' => 'Semana', 'lista' => 'Lista', 'grade' => 'Grade'] as $chave => $rotulo)
                <button type="button" wire:click="setView('{{ $chave }}')" role="tab"
                        @if ($view === $chave) aria-selected="true" @endif
                        class="rounded px-2.5 py-1 text-xs font-medium transition
                               {{ $view === $chave ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800' }}
                               {{ in_array($chave, ['mes', 'semana'], true) ? 'hidden md:block' : '' }}">
                    {{ $rotulo }}
                </button>
            @endforeach
        </div>
    </div>

    @if ($feedback)
        <x-alert type="success" class="mt-3">{{ $feedback }}</x-alert>
    @endif

    <div class="mt-3 flex flex-wrap gap-2">
        <select wire:model.live="status" class="input !w-auto !py-1.5 !text-xs" aria-label="Filtrar por status">
            <option value="todos">Todos os status</option>
            @foreach ($this->statusOptions() as $opcao)
                <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
            @endforeach
        </select>

        <select wire:model.live="type" class="input !w-auto !py-1.5 !text-xs" aria-label="Filtrar por tipo">
            <option value="todos">Todos os tipos</option>
            @foreach ($this->typeOptions() as $opcao)
                <option value="{{ $opcao->value }}">{{ $opcao->label() }}</option>
            @endforeach
        </select>

        @if ($this->accounts()->isNotEmpty())
            <select wire:model.live="accountId" class="input !w-auto !py-1.5 !text-xs" aria-label="Filtrar por conta">
                <option value="">Todas as contas</option>
                @foreach ($this->accounts() as $conta)
                    <option value="{{ $conta->id }}">{{ $conta->handle() }}</option>
                @endforeach
            </select>
        @endif

        @if ($this->campaigns()->isNotEmpty())
            <select wire:model.live="campaignId" class="input !w-auto !py-1.5 !text-xs" aria-label="Filtrar por campanha">
                <option value="">Todas as campanhas</option>
                @foreach ($this->campaigns() as $campanha)
                    <option value="{{ $campanha->id }}">{{ $campanha->name }}</option>
                @endforeach
            </select>
        @endif
    </div>

    {{-- ---------------------------------------------------- MÊS e SEMANA --}}
    @if (in_array($view, ['mes', 'semana'], true))
        <div class="mt-4 hidden md:block">
            <div class="grid grid-cols-7 gap-px rounded-t-lg bg-slate-200 text-center text-xs font-medium dark:bg-slate-800">
                @foreach (['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'] as $dia)
                    <div class="bg-slate-50 py-2 dark:bg-slate-900">{{ $dia }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7 gap-px overflow-hidden rounded-b-lg bg-slate-200 dark:bg-slate-800">
                @foreach ($this->days() as $dia)
                    @php
                        $chave = $dia->format('Y-m-d');
                        $doDia = $this->postsByDay()[$chave] ?? collect();
                        $foraDoMes = $view === 'mes' && $dia->format('Y-m') !== $ancoraMes;
                    @endphp
                    <div class="min-h-28 bg-white p-1.5 dark:bg-slate-900 {{ $foraDoMes ? 'opacity-40' : '' }}"
                         @drop.prevent="$wire.reschedule(arrastando, '{{ $chave }}'); arrastando = null"
                         @dragover.prevent>
                        <p class="mb-1 text-right text-[11px] {{ $chave === $hoje ? 'font-bold text-brand-600' : 'text-slate-400' }}">
                            {{ $dia->format('j') }}
                        </p>

                        @foreach ($doDia as $post)
                            <a href="{{ route('painel.posts.show', $post) }}"
                               draggable="{{ $post->status->isMovableInCalendar() ? 'true' : 'false' }}"
                               @dragstart="arrastando = {{ $post->id }}"
                               class="mb-1 block truncate rounded px-1.5 py-1 text-[11px] leading-tight ring-1 ring-inset {{ $post->status->badgeClasses() }}"
                               title="{{ display_datetime($post->scheduled_at, $post->client) }} — {{ $post->status->label() }}">
                                {{ display_time($post->scheduled_at, $post->client) }}
                                {{ \Illuminate\Support\Str::limit($post->caption ?: $post->type->label(), 22) }}
                            </a>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Mesma informação, empilhada, para telas estreitas. --}}
        <div class="md:hidden">
            @include('livewire.calendar.partials.lista', ['agrupados' => $this->postsByDay()])
        </div>

    {{-- ----------------------------------------------------------- LISTA --}}
    @elseif ($view === 'lista')
        <div class="mt-4">
            @include('livewire.calendar.partials.lista', ['agrupados' => $this->postsByDay()])
        </div>

    {{-- ---------------------------------------------- GRADE DE FEED 3×N --}}
    @else
        <div class="mt-4">
            @if ($this->posts()->isEmpty())
                <div class="card">
                    <x-empty-state title="Nada publicado ainda">
                        A grade simula o perfil do Instagram e mostra apenas o que já foi publicado.
                    </x-empty-state>
                </div>
            @else
                <div class="mx-auto grid max-w-2xl grid-cols-3 gap-0.5">
                    @foreach ($this->posts() as $post)
                        @php $capa = $post->postMedia->first()?->mediaAsset; @endphp
                        <a href="{{ route('painel.posts.show', $post) }}"
                           class="relative block aspect-square bg-slate-100 dark:bg-slate-800"
                           title="{{ display_date($post->published_at, $post->client) }}">
                            @if ($capa)
                                <img src="{{ $capa->thumbUrl() }}" alt="" loading="lazy" class="absolute inset-0 size-full object-cover">
                            @endif
                            @if ($post->postMedia->count() > 1)
                                <span class="absolute right-1 top-1 rounded bg-black/60 px-1 text-[10px] text-white">❏</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</div>
