@extends('layouts.app')
@section('title', 'Painel')
@section('subtitle', 'O que exige ação agora')

@section('content')
    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Aguardando cliente" :value="$awaitingClient->count()" tone="warn" hint="posts parados na aprovação" />
        <x-stat label="Vence em 24h" :value="$approvalsDueSoon->count()" tone="warn" hint="prazos de aprovação" />
        <x-stat label="Publicações com falha" :value="$failedPosts->count()" :tone="$failedPosts->isEmpty() ? 'good' : 'alert'" hint="exigem diagnóstico" />
        <x-stat label="Tokens expirando" :value="$expiringAccounts->count()" :tone="$expiringAccounts->isEmpty() ? 'good' : 'alert'" hint="nos próximos 7 dias" />
    </div>

    <div class="mt-6 grid gap-4 lg:grid-cols-2">
        <section class="card">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Travado com o cliente</h2>
            </header>

            @forelse ($awaitingClient as $post)
                <a href="{{ route('painel.posts.show', $post) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ Str::limit($post->caption ?: 'Sem legenda', 60) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $post->client?->name }} · {{ $post->socialAccount?->handle() ?? 'sem conta' }} ·
                            {{ display_datetime($post->scheduled_at, $post->client) }}
                        </p>
                    </div>
                    <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                </a>
            @empty
                <x-empty-state title="Nada travado com o cliente">
                    Quando um post for enviado para aprovação ele aparece aqui, com o prazo de decisão.
                </x-empty-state>
            @endforelse
        </section>

        <section class="card">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Publicações com falha</h2>
            </header>

            @forelse ($failedPosts as $post)
                <a href="{{ route('painel.posts.show', $post) }}" class="block border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                    <p class="truncate text-sm font-medium">{{ Str::limit($post->caption ?: 'Sem legenda', 60) }}</p>
                    <p class="text-xs text-rose-600 dark:text-rose-400">{{ Str::limit($post->last_error ?: 'Erro não registrado', 90) }}</p>
                </a>
            @empty
                <x-empty-state title="Nenhuma falha de publicação">
                    Quando o motor de publicação falhar, o erro e a próxima ação aparecem aqui.
                </x-empty-state>
            @endforelse
        </section>

        <section class="card">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Contas que vão parar de publicar</h2>
            </header>

            @forelse ($expiringAccounts as $account)
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $account->handle() }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $account->client?->name }}</p>
                    </div>
                    <span class="text-xs font-medium text-amber-600 dark:text-amber-400">
                        expira {{ display_date($account->token_expires_at, $account->client) }}
                    </span>
                </div>
            @empty
                <x-empty-state title="Todos os tokens em dia">
                    A renovação automática roda diariamente às 03:00 UTC e avisa aqui se falhar.
                </x-empty-state>
            @endforelse
        </section>

        <section class="card">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Minhas tarefas</h2>
            </header>

            @forelse ($myTasks as $task)
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $task->title }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $task->client?->name }}</p>
                    </div>
                    <span class="shrink-0 text-xs {{ $task->isOverdue() ? 'font-semibold text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">
                        {{ $task->due_at ? display_date($task->due_at, $task->client) : 'sem prazo' }}
                    </span>
                </div>
            @empty
                <x-empty-state title="Sem tarefas abertas para você">
                    Tarefas atribuídas a você aparecem aqui, ordenadas pelo prazo.
                </x-empty-state>
            @endforelse
        </section>
    </div>

    <section class="card mt-4">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Volume publicado no mês</h2>
        </header>

        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($clients as $client)
                <div class="flex items-center justify-between px-4 py-2.5">
                    <a href="{{ route('painel.clients.show', $client) }}" class="text-sm font-medium hover:underline">{{ $client->name }}</a>
                    <span class="text-sm tabular-nums text-slate-600 dark:text-slate-300">{{ $publishedThisMonth[$client->id] ?? 0 }}</span>
                </div>
            @endforeach
        </div>
    </section>
@endsection
