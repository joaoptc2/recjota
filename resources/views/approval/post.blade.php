@extends('layouts.approval')
@section('title', 'Aprovar post')

@section('content')
    @php
        $decidido = $aprovacao === null;
        $midias = $post->postMedia;
    @endphp

    {{-- Mídia primeiro: é o que a pessoa quer ver antes de qualquer texto. --}}
    <article class="card overflow-hidden">
        @if ($midias->isNotEmpty())
            <div class="relative aspect-square bg-slate-100 dark:bg-slate-800">
                <img src="{{ $midias->first()->mediaAsset?->previewUrl() }}" alt="{{ $midias->first()->alt_text }}"
                     class="absolute inset-0 size-full object-cover">
                @if ($midias->count() > 1)
                    <span class="absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white">
                        1/{{ $midias->count() }}
                    </span>
                @endif
            </div>

            @if ($midias->count() > 1)
                <div class="flex gap-1.5 overflow-x-auto p-2">
                    @foreach ($midias as $item)
                        <img src="{{ $item->mediaAsset?->thumbUrl() }}" alt=""
                             class="size-14 shrink-0 rounded object-cover">
                    @endforeach
                </div>
            @endif
        @endif

        <div class="p-4">
            <p class="whitespace-pre-line text-sm leading-relaxed">{{ $post->caption ?: 'Sem legenda.' }}</p>

            <dl class="mt-4 space-y-1 text-xs text-slate-500 dark:text-slate-400">
                <div class="flex justify-between gap-3">
                    <dt>Previsto para</dt>
                    <dd class="font-medium text-slate-700 dark:text-slate-200">
                        {{ $post->scheduled_at ? display_datetime($post->scheduled_at, $client) : 'a combinar' }}
                    </dd>
                </div>
                <div class="flex justify-between gap-3">
                    <dt>Formato</dt>
                    <dd>{{ $post->type->label() }}</dd>
                </div>
                @if ($aprovacao?->due_at)
                    <div class="flex justify-between gap-3">
                        <dt>Prazo para responder</dt>
                        <dd>{{ display_datetime($aprovacao->due_at, $client) }}</dd>
                    </div>
                @endif
            </dl>
        </div>
    </article>

    @if ($decidido)
        <div class="card mt-4 p-4 text-center">
            <p class="text-sm font-medium">Este post já foi decidido.</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Situação atual: {{ $post->status->label() }}.
            </p>
        </div>
    @else
        {{-- Três ações explícitas. Aprovar é um toque: é reversível pela
             agência, e confirmar aqui só adicionaria atrito (Seção 9.1). --}}
        <div x-data="{ aberto: null }" class="mt-4 space-y-3">
            <form method="POST" action="{{ route('aprovacao.decidir', $token) }}">
                @csrf
                <input type="hidden" name="post_id" value="{{ $post->id }}">
                <input type="hidden" name="decisao" value="approved">
                <button type="submit" class="btn-primary w-full !py-3.5 !text-base">Aprovar</button>
            </form>

            <div class="grid grid-cols-2 gap-3">
                <button type="button" @click="aberto = aberto === 'ajustes' ? null : 'ajustes'"
                        class="btn-secondary !py-3">Solicitar ajustes</button>
                <button type="button" @click="aberto = aberto === 'reprovar' ? null : 'reprovar'"
                        class="btn-secondary !py-3 !text-rose-700 dark:!text-rose-300">Reprovar</button>
            </div>

            @foreach (['ajustes' => ['changes_requested', 'O que precisa mudar?', 'Enviar pedido de ajuste'], 'reprovar' => ['rejected', 'Por que reprovar?', 'Confirmar reprovação']] as $chave => [$valor, $titulo, $acao])
                <form method="POST" action="{{ route('aprovacao.decidir', $token) }}" x-show="aberto === '{{ $chave }}'" x-cloak class="card p-4">
                    @csrf
                    <input type="hidden" name="post_id" value="{{ $post->id }}">
                    <input type="hidden" name="decisao" value="{{ $valor }}">

                    <label for="nota-{{ $chave }}" class="label">{{ $titulo }}</label>
                    <textarea id="nota-{{ $chave }}" name="nota" rows="3" required class="input"
                              placeholder="Escreva com suas palavras. A equipe recebe exatamente isto."></textarea>

                    <label for="nome-{{ $chave }}" class="label mt-3">Seu nome</label>
                    <input id="nome-{{ $chave }}" name="nome" type="text" class="input"
                           value="{{ $link->recipient_name }}" placeholder="Para a equipe saber quem pediu">

                    <button type="submit" class="btn-primary mt-3 w-full">{{ $acao }}</button>
                </form>
            @endforeach
        </div>
    @endif

    @if ($comentarios->isNotEmpty())
        <section class="card mt-5">
            <h2 class="border-b border-slate-200 px-4 py-3 text-sm font-semibold dark:border-slate-800">Conversa</h2>
            @foreach ($comentarios as $comentario)
                <div class="border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <p class="text-sm font-medium">{{ $comentario->authorName() }}</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-600 dark:text-slate-300">{{ $comentario->body }}</p>
                    <p class="mt-1 text-xs text-slate-400">{{ display_datetime($comentario->created_at, $client) }}</p>
                </div>
            @endforeach
        </section>
    @endif
@endsection
