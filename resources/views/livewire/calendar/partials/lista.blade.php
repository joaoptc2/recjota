@php $agrupados = collect($agrupados)->sortKeys(); @endphp

@forelse ($agrupados as $dia => $posts)
    <section class="mb-4">
        <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
            {{ \Illuminate\Support\Carbon::parse($dia)->translatedFormat('D, d \d\e F') }}
        </h3>

        <div class="card divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($posts as $post)
                <div class="flex items-center gap-3 p-3">
                    @php $capa = $post->postMedia->first()?->mediaAsset; @endphp
                    <span class="size-12 shrink-0 overflow-hidden rounded bg-slate-100 dark:bg-slate-800">
                        @if ($capa)
                            <img src="{{ $capa->thumbUrl() }}" alt="" class="size-full object-cover">
                        @endif
                    </span>

                    <a href="{{ route('painel.posts.show', $post) }}" class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ \Illuminate\Support\Str::limit($post->caption ?: $post->type->label(), 60) }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ display_time($post->scheduled_at, $post->client) }} ·
                            {{ $post->type->label() }} ·
                            {{ $post->socialAccount?->handle() ?? 'conta a definir' }}
                        </p>
                    </a>

                    <x-badge :classes="$post->status->badgeClasses()">{{ $post->status->label() }}</x-badge>
                </div>
            @endforeach
        </div>
    </section>
@empty
    <div class="card">
        <x-empty-state title="Nenhum post neste período">
            Use as setas para navegar, ou crie um post para o calendário começar a encher.
        </x-empty-state>
    </div>
@endforelse
