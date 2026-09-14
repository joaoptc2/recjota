@extends('layouts.portal')
@section('title', 'Post para revisão')

@section('content')
    {{-- Mobile primeiro: mídia, legenda, data e conversa numa coluna só (Seção 6.6). --}}
    <article class="card p-4">
        <div class="flex flex-wrap items-center gap-2">
            <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
            <span class="text-xs text-slate-500 dark:text-slate-400">
                previsto para {{ display_datetime($post->scheduled_at, $post->client) }}
            </span>
        </div>

        <p class="mt-4 whitespace-pre-line text-sm leading-relaxed">{{ $post->caption ?: 'Sem legenda.' }}</p>

        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
            {{ $post->type->label() }} · {{ $post->socialAccount?->handle() ?? 'conta a definir' }}
        </p>
    </article>

    <section class="card mt-4">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Comentários</h2>
        </header>

        @forelse ($comments as $comment)
            <div class="border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                <p class="text-sm font-medium">{{ $comment->authorName() }}</p>
                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $comment->body }}</p>
                <p class="mt-1 text-xs text-slate-400">{{ display_datetime($comment->created_at, $post->client) }}</p>
            </div>
        @empty
            <x-empty-state title="Nenhum comentário ainda">
                Você poderá comentar e aprovar por aqui a partir da Fase 3.
            </x-empty-state>
        @endforelse
    </section>
@endsection
