@extends('layouts.approval')
@section('title', 'Aprovações pendentes')
@section('subtitle', $posts->count().' aguardando você')

@section('content')
    @forelse ($posts as $post)
        <article class="card mb-4 overflow-hidden">
            @if ($capa = $post->postMedia->first()?->mediaAsset)
                <div class="relative aspect-square bg-slate-100 dark:bg-slate-800">
                    <img src="{{ $capa->previewUrl() }}" alt="" class="absolute inset-0 size-full object-cover">
                    @if ($post->postMedia->count() > 1)
                        <span class="absolute right-2 top-2 rounded-full bg-black/60 px-2 py-0.5 text-xs text-white">
                            {{ $post->postMedia->count() }} itens
                        </span>
                    @endif
                </div>
            @endif

            <div class="p-4">
                <p class="whitespace-pre-line text-sm leading-relaxed">{{ \Illuminate\Support\Str::limit($post->caption ?: 'Sem legenda.', 280) }}</p>
                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    {{ $post->type->label() }} ·
                    {{ $post->scheduled_at ? display_datetime($post->scheduled_at, $client) : 'data a combinar' }}
                </p>

                <form method="POST" action="{{ route('aprovacao.decidir', $token) }}" class="mt-3">
                    @csrf
                    <input type="hidden" name="post_id" value="{{ $post->id }}">
                    <input type="hidden" name="decisao" value="approved">
                    <button type="submit" class="btn-primary w-full !py-3">Aprovar este</button>
                </form>
            </div>
        </article>
    @empty
        <div class="card">
            <x-empty-state title="Nada pendente">
                Você está em dia. Quando a equipe enviar algo novo, você recebe um e-mail.
            </x-empty-state>
        </div>
    @endforelse
@endsection
