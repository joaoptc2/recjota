@extends('layouts.app')
@section('title', $client->name)
@section('subtitle', $client->legal_name ?: 'Perfil do cliente')

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="card p-4 lg:col-span-1">
            <h2 class="text-sm font-semibold">Configuração de aprovação</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Aprovação obrigatória</dt>
                    <dd>{{ $client->settings?->approval_required ? 'Sim' : 'Não' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Revisão interna antes</dt>
                    <dd>{{ $client->settings?->internal_review_required ? 'Sim' : 'Não' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Prazo de decisão</dt>
                    <dd>{{ $client->settings?->approval_deadline_hours ?? 48 }} h</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Aprovadores mínimos</dt>
                    <dd>{{ $client->settings?->min_approvals ?? 1 }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Publicar ao aprovar</dt>
                    <dd>{{ $client->settings?->auto_publish_on_approval ? 'Sim' : 'Não' }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500 dark:text-slate-400">Fuso de exibição</dt>
                    <dd>{{ $client->displayTimezone() }}</dd>
                </div>
            </dl>
        </section>

        <section class="card lg:col-span-2">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Contas conectadas</h2>
            </header>

            @forelse ($client->socialAccounts as $account)
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $account->handle() }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $account->platform->label() }} · {{ $account->account_type->label() }}
                        </p>
                    </div>
                    <x-badge :classes="$account->connection_status->isHealthy()
                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30'
                        : 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30'">
                        {{ $account->connection_status->label() }}
                    </x-badge>
                </div>
            @empty
                <x-empty-state title="Nenhuma conta conectada">
                    A conexão com o Instagram entra na Fase 4. Até lá, o conteúdo pode ser criado e
                    aprovado normalmente.
                </x-empty-state>
            @endforelse
        </section>

        <section class="card lg:col-span-3">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Últimos posts</h2>
            </header>

            @forelse ($recentPosts as $post)
                <a href="{{ route('painel.posts.show', $post) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ Str::limit($post->caption ?: 'Sem legenda', 70) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $post->type->label() }} · {{ display_datetime($post->scheduled_at, $client) }}
                        </p>
                    </div>
                    <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                </a>
            @empty
                <x-empty-state title="Nenhum post ainda">
                    O composer de postagem chega na Fase 2. O schema já está pronto para receber.
                </x-empty-state>
            @endforelse
        </section>

        <section class="card lg:col-span-3">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Pessoas com acesso</h2>
            </header>

            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($client->users as $member)
                    <div class="flex items-center justify-between gap-3 px-4 py-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $member->name }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $member->email }}</p>
                        </div>
                        <span class="text-xs text-slate-500 dark:text-slate-400">
                            {{ \App\Support\Enums\RoleName::tryFrom($member->pivot->role)?->label() ?? $member->pivot->role }}
                        </span>
                    </div>
                @endforeach
            </div>
        </section>
    </div>
@endsection
