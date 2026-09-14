@extends('layouts.portal')
@section('title', 'Seu painel')
@section('subtitle', 'O que precisa de você agora')

@section('content')
    @if ($awaitingMe->isNotEmpty())
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/60">
            <p class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                {{ $awaitingMe->count() }} {{ $awaitingMe->count() === 1 ? 'post aguarda' : 'posts aguardam' }} sua aprovação
            </p>
            @if ($nextApprovalDeadline)
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
                    O prazo mais próximo é {{ display_datetime($nextApprovalDeadline, $client) }}.
                </p>
            @endif
        </div>

        <div class="mt-4 space-y-3">
            @foreach ($awaitingMe as $post)
                <a href="{{ route('portal.posts.show', $post) }}" class="card block p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ Str::limit($post->caption ?: 'Sem legenda', 70) }}</p>
                            <p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                                {{ $post->type->label() }} · previsto para {{ display_datetime($post->scheduled_at, $client) }}
                            </p>
                        </div>
                        <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="card">
            <x-empty-state title="Nada esperando por você">
                Quando a equipe enviar um post para aprovação, você recebe um e-mail com link direto —
                sem precisar de senha.
            </x-empty-state>
        </div>
    @endif

    <div class="mt-6 grid grid-cols-2 gap-3">
        <x-stat label="Publicados no mês" :value="$publishedThisMonth" />
        <x-stat label="Próximas publicações" :value="$upcoming->count()" />
    </div>

    <section class="card mt-6">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Próximas publicações</h2>
        </header>

        @forelse ($upcoming as $post)
            <a href="{{ route('portal.posts.show', $post) }}" class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                <p class="min-w-0 truncate text-sm">{{ Str::limit($post->caption ?: 'Sem legenda', 50) }}</p>
                <span class="shrink-0 text-xs text-slate-500 dark:text-slate-400">{{ display_datetime($post->scheduled_at, $client) }}</span>
            </a>
        @empty
            <x-empty-state title="Nenhuma publicação agendada">
                Assim que a equipe agendar conteúdo aprovado, ele aparece aqui.
            </x-empty-state>
        @endforelse
    </section>
@endsection
