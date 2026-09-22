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
            <header class="flex items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Contas conectadas</h2>
                @can('create', \App\Models\SocialAccount::class)
                    <a href="{{ route('painel.integrations.instagram.connect', $client) }}"
                       class="inline-flex items-center rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700">
                        Conectar Instagram
                    </a>
                @endcan
            </header>

            @forelse ($client->socialAccounts as $account)
                <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $account->handle() }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $account->platform->label() }} · {{ $account->account_type->label() }}
                            @if ($account->token_expires_at)
                                · acesso válido até {{ display_date($account->token_expires_at, $client) }}
                            @endif
                        </p>
                        @if ($account->needsReconnection() && $account->last_error)
                            <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $account->last_error }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-badge :classes="$account->connection_status->isHealthy()
                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30'
                            : 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30'">
                            {{ $account->connection_status->label() }}
                        </x-badge>
                        @if ($account->needsReconnection() && $account->platform === \App\Support\Enums\SocialPlatform::Instagram)
                            @can('reconnect', $account)
                                <a href="{{ route('painel.integrations.instagram.connect', ['client' => $client, 'conta' => $account->ulid]) }}"
                                   class="inline-flex items-center rounded-lg border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-950">
                                    Reconectar
                                </a>
                            @endcan
                        @endif
                    </div>
                </div>
            @empty
                <x-empty-state title="Nenhuma conta conectada">
                    @can('create', \App\Models\SocialAccount::class)
                        Clique em "Conectar Instagram" e autorize com um perfil que administre a conta
                        profissional do cliente. Sem isso, os posts aprovados não têm para onde ir.
                    @else
                        Peça a um gestor de contas para conectar o Instagram deste cliente. Até lá, o
                        conteúdo pode ser criado e aprovado normalmente.
                    @endcan
                </x-empty-state>
            @endforelse
        </section>

        <section class="card lg:col-span-3">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                <h2 class="text-sm font-semibold">Arquivos na nuvem</h2>
                @can('create', \App\Models\CloudConnection::class)
                    <div class="flex flex-wrap gap-2">
                        @foreach (\App\Support\Enums\CloudProvider::cases() as $provedor)
                            <a href="{{ route('painel.integrations.cloud.connect', ['provider' => $provedor->slug(), 'client' => $client]) }}"
                               class="inline-flex items-center rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                Conectar {{ $provedor->label() }}
                            </a>
                        @endforeach
                    </div>
                @endcan
            </header>

            @error('cloud')
                <p class="border-b border-rose-100 bg-rose-50 px-4 py-2 text-sm text-rose-700 dark:border-rose-900 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</p>
            @enderror

            @forelse ($client->cloudConnections as $conexao)
                <div class="flex flex-col gap-2 border-b border-slate-100 px-4 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $conexao->provider->label() }} · {{ $conexao->label() }}</p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $conexao->mediaAssets()->count() }} arquivo(s) importado(s)
                            @if ($conexao->consent_given_at)
                                · autorizado em {{ display_date($conexao->consent_given_at, $client) }}
                            @endif
                        </p>
                        @if ($conexao->needsReconnection() && $conexao->last_error)
                            <p class="mt-1 text-xs text-rose-600 dark:text-rose-300">{{ $conexao->last_error }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-badge :classes="$conexao->status->isHealthy()
                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30'
                            : 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30'">
                            {{ $conexao->status->label() }}
                        </x-badge>
                        @can('update', $conexao)
                            @if ($conexao->needsReconnection())
                                <a href="{{ route('painel.integrations.cloud.connect', ['provider' => $conexao->provider->slug(), 'client' => $client]) }}"
                                   class="inline-flex items-center rounded-lg border border-rose-300 px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-950">
                                    Reconectar
                                </a>
                            @else
                                <form method="POST" action="{{ route('painel.integrations.cloud.disconnect', $conexao) }}"
                                      onsubmit="return confirm('Desconectar {{ $conexao->provider->label() }} ({{ $conexao->label() }})? Os arquivos importados continuam na biblioteca, mas só publicam depois de reconectar.')">
                                    @csrf
                                    <button type="submit" class="inline-flex items-center rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">
                                        Desconectar
                                    </button>
                                </form>
                            @endif
                        @endcan
                    </div>
                </div>
            @empty
                <x-empty-state title="Nenhuma nuvem conectada">
                    @can('create', \App\Models\CloudConnection::class)
                        Conecte o Google Drive ou o OneDrive do cliente para escolher imagens e vídeos direto de lá,
                        sem baixar e subir de novo. O original continua na nuvem; aqui fica só a miniatura.
                    @else
                        Peça a um gestor de contas para conectar o Google Drive ou o OneDrive deste cliente.
                        Até lá, envie os arquivos pela biblioteca.
                    @endcan
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
