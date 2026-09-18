<div x-data="{ arrastando: null }">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" wire:model.live="mine" class="rounded border-slate-300 text-brand-600 focus:ring-brand-600">
            Só as minhas
        </label>

        @if ($client)
            <button type="button" wire:click="$toggle('showForm')" class="btn-primary !py-2">
                {{ $showForm ? 'Cancelar' : 'Nova tarefa' }}
            </button>
        @endif
    </div>

    @if ($showForm && $client)
        <form wire:submit="create" class="card mt-3 space-y-3 p-4">
            <div>
                <label for="titulo" class="label">Título</label>
                <input id="titulo" type="text" wire:model="title" class="input" required>
                @error('title')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <label for="responsavel" class="label">Responsável</label>
                    <select id="responsavel" wire:model="assigneeId" class="input">
                        <option value="">Ninguém</option>
                        @foreach ($this->assignees() as $pessoa)
                            <option value="{{ $pessoa->id }}">{{ $pessoa->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="prazo" class="label">Prazo</label>
                    <input id="prazo" type="datetime-local" wire:model="dueAt" class="input">
                </div>
                <div>
                    <label for="prioridade" class="label">Prioridade</label>
                    <select id="prioridade" wire:model="priority" class="input">
                        @foreach (\App\Support\Enums\TaskPriority::cases() as $p)
                            <option value="{{ $p->value }}">{{ $p->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-primary">Criar tarefa</button>
        </form>
    @endif

    <div class="mt-4 grid gap-3 md:grid-cols-4">
        @foreach ($this->columns() as $chave => $coluna)
            <section class="rounded-xl bg-slate-100 p-2 dark:bg-slate-800/60"
                     @drop.prevent="$wire.move(arrastando, '{{ $chave }}'); arrastando = null"
                     @dragover.prevent>
                <h3 class="px-1 py-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                    {{ $coluna['status']->label() }}
                    <span class="ml-1 text-slate-400">{{ $coluna['tarefas']->count() }}</span>
                </h3>

                <div class="space-y-2">
                    @foreach ($coluna['tarefas'] as $tarefa)
                        <article draggable="true" @dragstart="arrastando = {{ $tarefa->id }}"
                                 class="card cursor-grab p-3 active:cursor-grabbing">
                            <p class="text-sm font-medium">{{ $tarefa->title }}</p>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                                {{ $tarefa->client?->name }}
                                @if ($tarefa->assignee) · {{ $tarefa->assignee->name }} @endif
                            </p>
                            @if ($tarefa->due_at)
                                <p class="mt-1 text-xs {{ $tarefa->isOverdue() ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-400' }}">
                                    {{ $tarefa->isOverdue() ? 'atrasada · ' : '' }}{{ display_date($tarefa->due_at, $tarefa->client) }}
                                </p>
                            @endif
                        </article>
                    @endforeach

                    @if ($coluna['tarefas']->isEmpty())
                        <p class="px-1 py-6 text-center text-xs text-slate-400">vazio</p>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
