@extends('layouts.app')
@section('title', 'Post')
@section('subtitle', $post->client?->name)

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        <section class="card p-4 lg:col-span-2">
            <div class="flex flex-wrap items-center gap-2">
                <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                <x-badge :classes="$post->approval_status->badgeClasses()">{{ $post->approval_status->label() }}</x-badge>
                <span class="text-xs text-slate-500 dark:text-slate-400">versão {{ $post->current_version }}</span>
            </div>

            <p class="mt-4 whitespace-pre-line text-sm">{{ $post->caption ?: 'Sem legenda.' }}</p>

            @if ($post->first_comment)
                <div class="mt-4 rounded-lg bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Primeiro comentário</p>
                    <p class="mt-1 whitespace-pre-line">{{ $post->first_comment }}</p>
                </div>
            @endif

            <dl class="mt-5 grid grid-cols-2 gap-3 text-sm">
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Tipo</dt>
                    <dd>{{ $post->type->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Conta</dt>
                    <dd>{{ $post->socialAccount?->handle() ?? '—' }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-slate-500 dark:text-slate-400">Agendamento</dt>
                    {{-- Fuso do cliente com o UTC ao lado: elimina a classe de bug mais comum (Seção 8.4). --}}
                    <dd>{{ display_local_with_utc($post->scheduled_at, $post->client) }}</dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap gap-2 text-xs text-slate-500 dark:text-slate-400">
                <span>{{ $post->captionLength() }}/{{ config('agency.limits.caption_max_chars') }} caracteres</span>
                <span>· {{ $post->hashtagCount() }}/{{ config('agency.limits.hashtags_max') }} hashtags</span>
                <span>· {{ $post->mentionCount() }}/{{ config('agency.limits.mentions_max') }} menções</span>
            </div>
        </section>

        <section class="card lg:col-span-1">
            <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Conversa</h2>
            </header>

            @forelse ($comments as $comment)
                <div class="border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <div class="flex items-center gap-2">
                        <p class="text-sm font-medium">{{ $comment->authorName() }}</p>
                        @if ($comment->is_internal)
                            <x-badge classes="bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30">interno</x-badge>
                        @endif
                    </div>
                    <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">{{ $comment->body }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ display_datetime($comment->created_at, $post->client) }}</p>
                </div>
            @empty
                <x-empty-state title="Nenhum comentário">
                    Comentários internos e do cliente aparecem aqui, presos à versão do post.
                </x-empty-state>
            @endforelse
        </section>
    </div>
@endsection
