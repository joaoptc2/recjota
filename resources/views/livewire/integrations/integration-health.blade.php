<div>
    @php
        $contas = $this->accounts();
        $problemas = $contas->filter(fn ($c) => $c->needsReconnection())->count();
        $vencendo = $contas->filter(fn ($c) => ($d = $c->daysUntilTokenExpires()) !== null && $d < \App\Livewire\Integrations\IntegrationHealth::DIAS_ALERTA)->count();
    @endphp

    <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
        <x-stat label="Contas" :value="$contas->count()" hint="conectadas aos clientes que você alcança" />
        <x-stat label="Saudáveis" :value="$contas->count() - $problemas" :tone="$problemas === 0 ? 'good' : 'default'" hint="publicando normalmente" />
        <x-stat label="Precisam reconectar" :value="$problemas" :tone="$problemas === 0 ? 'good' : 'alert'" hint="token expirado, revogado ou com erro" />
        <x-stat label="Token vence em < 7 dias" :value="$vencendo" :tone="$vencendo === 0 ? 'good' : 'warn'" hint="a renovação automática tenta antes" />
    </div>

    <div class="card mt-6 overflow-hidden">
        @forelse ($contas as $conta)
            @php
                $dias = $conta->daysUntilTokenExpires();
                $cota = $conta->getRelation('latestQuota');
                $cotaAtual = $cota !== null && $cota->window_start->greaterThan(now()->subDay()) ? $cota : null;
                $aberto = $logsDe === $conta->getKey();
            @endphp
            <article class="border-b border-slate-100 last:border-0 dark:border-slate-800">
                <div class="flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center">
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span class="mt-1.5 size-3 shrink-0 rounded-full {{ $this->lightClass($conta) }}"
                              title="{{ $conta->connection_status?->label() }}" aria-label="Status: {{ $conta->connection_status?->label() }}"></span>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">
                                {{ $conta->handle() }}
                                <span class="font-normal text-slate-500 dark:text-slate-400">· {{ $conta->client?->name ?? 'cliente removido' }}</span>
                            </p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ $conta->platform->label() }} · {{ $conta->account_type?->label() ?? 'tipo indisponível' }}
                                · {{ $conta->connection_status?->label() }}
                            </p>
                            @if ($conta->last_error)
                                <p class="mt-1 text-xs {{ $conta->needsReconnection() ? 'text-rose-600 dark:text-rose-300' : 'text-slate-500 dark:text-slate-400' }}">
                                    Último erro: {{ $conta->last_error }}
                                </p>
                            @endif
                        </div>
                    </div>

                    <dl class="grid shrink-0 grid-cols-2 gap-x-6 gap-y-1 text-xs lg:w-72">
                        <dt class="text-slate-500 dark:text-slate-400">Token vence em</dt>
                        <dd class="font-medium {{ $dias !== null && $dias < \App\Livewire\Integrations\IntegrationHealth::DIAS_ALERTA ? ($dias < 0 ? 'text-rose-600 dark:text-rose-300' : 'text-amber-600 dark:text-amber-300') : '' }}">
                            @if ($dias === null)
                                indisponível
                            @elseif ($dias < 0)
                                vencido há {{ abs($dias) }} dia(s)
                            @else
                                {{ $dias }} dia(s)
                            @endif
                        </dd>
                        <dt class="text-slate-500 dark:text-slate-400">Cota (24h)</dt>
                        <dd class="font-medium {{ $cotaAtual?->isExhausted() ? 'text-rose-600 dark:text-rose-300' : '' }}">
                            @if ($cotaAtual)
                                {{ $cotaAtual->used_count }}/{{ $cotaAtual->quota_total }}
                                <span class="font-normal text-slate-400">desde {{ display_time($cotaAtual->window_start, $conta->client) }}</span>
                            @else
                                indisponível
                            @endif
                        </dd>
                    </dl>

                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        @can('viewAny', \App\Models\PublishLog::class)
                            <button type="button" wire:click="toggleLogs({{ $conta->getKey() }})" class="btn-secondary !px-3 !py-1.5 !text-xs" aria-expanded="{{ $aberto ? 'true' : 'false' }}">
                                {{ $aberto ? 'Fechar histórico' : 'Histórico de publicação' }}
                            </button>
                        @endcan
                        @if ($conta->client && $conta->platform === \App\Support\Enums\SocialPlatform::Instagram)
                            @can('reconnect', $conta)
                                <a href="{{ route('painel.integrations.instagram.connect', ['client' => $conta->client, 'conta' => $conta->ulid]) }}"
                                   class="inline-flex items-center rounded-lg border px-2.5 py-1.5 text-xs font-semibold {{ $conta->needsReconnection() ? 'border-rose-300 text-rose-700 hover:bg-rose-50 dark:border-rose-700 dark:text-rose-300 dark:hover:bg-rose-950' : 'border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800' }}">
                                    Reconectar
                                </a>
                            @endcan
                        @endif
                    </div>
                </div>

                @if ($aberto)
                    <div class="border-t border-slate-100 bg-slate-50 px-4 py-3 dark:border-slate-800 dark:bg-slate-900/60">
                        <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Últimas {{ \App\Livewire\Integrations\IntegrationHealth::MAX_LOGS }} chamadas do motor de publicação
                        </h3>
                        @forelse ($this->logs() as $log)
                            <div class="mt-2 rounded-lg bg-white p-3 text-xs dark:bg-slate-900">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-badge :classes="$log->succeeded
                                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30'
                                        : 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30'">
                                        {{ $log->succeeded ? 'ok' : 'falha' }}
                                    </x-badge>
                                    <span class="font-medium">{{ $log->stage->label() }}</span>
                                    <span class="text-slate-400">tentativa {{ $log->attempt }}</span>
                                    <span class="text-slate-400">{{ display_datetime($log->created_at, $conta->client) }}</span>
                                    @if ($log->post)
                                        <a href="{{ route('painel.posts.show', $log->post) }}" class="ml-auto text-brand-600 hover:underline dark:text-brand-400">ver post</a>
                                    @endif
                                </div>
                                @if ($log->error)
                                    <p class="mt-1 text-rose-600 dark:text-rose-300">{{ $log->error }}</p>
                                @endif
                            </div>
                        @empty
                            <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                                Nenhuma chamada registrada para esta conta ainda. O histórico começa no primeiro post agendado.
                            </p>
                        @endforelse
                    </div>
                @endif
            </article>
        @empty
            <x-empty-state title="Nenhuma conta conectada"
                           action="Ver clientes" :href="route('painel.clients.index')">
                Abra a página de um cliente e clique em "Conectar Instagram". Autorize com um perfil
                que administre a conta profissional do cliente; a conta aparece aqui com o semáforo,
                a validade do token e a cota de publicação.
            </x-empty-state>
        @endforelse
    </div>
</div>
