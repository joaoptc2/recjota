<div class="space-y-6">
    @if ($feedback)
        <x-alert type="success">{{ $feedback }}</x-alert>
    @endif
    @error('users')
        <x-alert type="error">{{ $message }}</x-alert>
    @enderror

    {{-- Saúde do sistema: primeiro, porque é o que exige ação (Seção 11.2) --}}
    <section class="card">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Saúde do sistema</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">O que o cron, a fila e as integrações estão fazendo agora.</p>
        </header>

        @php
            $batimento = $this->heartbeat();
            $cronParado = $this->cronParado();
        @endphp

        <div class="grid grid-cols-2 gap-3 p-4 sm:gap-4 lg:grid-cols-4">
            <x-stat label="Último batimento do cron"
                    :value="$batimento?->last_run_at ? display_time($batimento->last_run_at, auth()->user()) : 'nunca'"
                    :tone="$cronParado ? 'alert' : 'good'"
                    :hint="$batimento?->last_run_at ? display_datetime($batimento->last_run_at, auth()->user()) : 'o agendador nunca rodou'" />
            <x-stat label="Jobs falhados" :value="$this->failedJobsCount()" :tone="$this->failedJobsCount() === 0 ? 'good' : 'alert'" hint="na tabela failed_jobs" />
            <x-stat label="Tokens vencendo" :value="$this->expiringAccounts()->count()" :tone="$this->expiringAccounts()->isEmpty() ? 'good' : 'warn'" hint="nos próximos 7 dias" />
            <x-stat label="Fila" :value="$this->queueSize()" hint="jobs aguardando o cron" />
        </div>

        @if ($cronParado)
            <div class="mx-4 mb-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm dark:border-rose-900 dark:bg-rose-950/60">
                <p class="font-semibold text-rose-900 dark:text-rose-200">
                    O cron não bate há mais de {{ \App\Livewire\Settings\AgencySettings::CRON_PARADO_MINUTOS }} minutos.
                </p>
                <p class="mt-0.5 text-rose-800 dark:text-rose-300">
                    Sem ele nada é publicado, renovado ou enviado. Confira no hPanel se o cron job de 1 em 1 minuto
                    ainda existe e aponta para <code>cron.sh</code> (ou <code>cron.php</code>) desta instalação.
                </p>
            </div>
        @endif

        @if ($this->expiringAccounts()->isNotEmpty())
            <div class="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Tokens que vencem em 7 dias</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($this->expiringAccounts() as $conta)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span>{{ $conta->handle() }} <span class="text-slate-500 dark:text-slate-400">· {{ $conta->client?->name }}</span></span>
                            <span class="text-xs text-amber-600 dark:text-amber-300">vence em {{ max(0, $conta->daysUntilTokenExpires()) }} dia(s)</span>
                        </li>
                    @endforeach
                </ul>
                <a href="{{ route('painel.integrations') }}" class="mt-2 inline-block text-xs text-brand-600 hover:underline dark:text-brand-400">Abrir saúde das integrações</a>
            </div>
        @endif

        <div class="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Últimos jobs falhados</h3>
            @if ($saidaRetry)
                <pre class="mt-2 overflow-x-auto rounded-lg bg-slate-100 p-2 text-xs dark:bg-slate-800">{{ $saidaRetry }}</pre>
            @endif
            @forelse ($this->failedJobs() as $job)
                <div class="mt-2 flex flex-col gap-2 rounded-lg border border-slate-100 p-3 text-xs sm:flex-row sm:items-center dark:border-slate-800">
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ $job->nome }}</p>
                        <p class="truncate text-rose-600 dark:text-rose-300">{{ $job->resumo }}</p>
                        <p class="text-slate-400">{{ display_datetime($job->failed_at, auth()->user()) }} · fila {{ $job->queue }}</p>
                    </div>
                    <button type="button" wire:click="retryFailedJob('{{ $job->uuid }}')" class="btn-secondary !px-3 !py-1.5 !text-xs">
                        Tentar de novo
                    </button>
                </div>
            @empty
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Nenhum job falhado. Quando um falhar, o erro aparece aqui com o botão para reenfileirar.</p>
            @endforelse
        </div>

        <div class="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
            <h3 class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Backups do banco</h3>
            @php $backups = $this->backups(); @endphp
            @if ($backups === [])
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Nenhum backup ainda. O sistema gera um todo dia às 02:30 UTC e guarda os últimos 14; para gerar agora, use
                    "Fazer backup do banco agora" em <code>/manutencao</code>. Baixe uma cópia por semana para fora do servidor.
                </p>
            @else
                @php $ultimo = $backups[0]['quando']; @endphp
                <p class="mt-1 text-xs {{ $ultimo->lessThan(now()->subHours(36)) ? 'text-rose-600 dark:text-rose-300' : 'text-slate-500 dark:text-slate-400' }}">
                    Último backup {{ display_datetime($ultimo, auth()->user()) }}{{ $ultimo->lessThan(now()->subHours(36)) ? ' — há mais de um dia; confira se o cron está rodando.' : '.' }}
                    Baixe uma cópia por semana para fora do servidor: a hospedagem não garante backup próprio.
                </p>
                <ul class="mt-2 divide-y divide-slate-100 text-sm dark:divide-slate-800">
                    @foreach ($backups as $backup)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <span class="min-w-0 truncate">{{ $backup['nome'] }} <span class="text-xs text-slate-400">· {{ number_format($backup['bytes'] / 1024, 0, ',', '.') }} KB</span></span>
                            <a href="{{ route('painel.settings.backup', $backup['nome']) }}" class="btn-secondary !px-3 !py-1 !text-xs">Baixar</a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- Branding --}}
    <section class="card">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Dados e branding da agência</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">Aparecem no painel, nos e-mails e nos relatórios.</p>
        </header>
        <form wire:submit="saveBranding" class="grid gap-4 p-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="agencyName" class="label">Nome da agência</label>
                <input id="agencyName" type="text" wire:model="agencyName" class="input" maxlength="80">
                @error('agencyName')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="supportEmail" class="label">E-mail de suporte</label>
                <input id="supportEmail" type="email" wire:model="supportEmail" class="input">
                @error('supportEmail')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="primaryColor" class="label">Cor primária</label>
                <div class="flex items-center gap-2">
                    <input type="color" wire:model="primaryColor" class="size-10 shrink-0 cursor-pointer rounded border border-slate-300 bg-transparent p-0.5 dark:border-slate-700" aria-label="Escolher cor">
                    <input id="primaryColor" type="text" wire:model="primaryColor" class="input font-mono" placeholder="#4F46E5" maxlength="7">
                </div>
                @error('primaryColor')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="btn-primary">Salvar dados da agência</button>
            </div>
        </form>
    </section>

    {{-- Usuários --}}
    <section class="card">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Usuários da agência</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">Desativar bloqueia o acesso sem apagar histórico.</p>
        </header>
        <div class="divide-y divide-slate-100 dark:divide-slate-800">
            @foreach ($this->users() as $membro)
                <div class="flex items-center justify-between gap-3 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">
                            {{ $membro->name }}
                            @if ($membro->is(auth()->user()))<span class="text-xs font-normal text-slate-400">(você)</span>@endif
                        </p>
                        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $membro->email }} · {{ $membro->primaryRole()?->label() ?? 'Sem papel' }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <x-badge :classes="$membro->is_active
                            ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30'
                            : 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-400/30'">
                            {{ $membro->is_active ? 'Ativo' : 'Desativado' }}
                        </x-badge>
                        @can('update', $membro)
                            @unless ($membro->is(auth()->user()))
                                <button type="button" wire:click="toggleUser({{ $membro->getKey() }})"
                                        @if ($membro->is_active) wire:confirm="Desativar {{ $membro->name }}? A pessoa perde o acesso imediatamente." @endif
                                        class="btn-secondary !px-3 !py-1.5 !text-xs">
                                    {{ $membro->is_active ? 'Desativar' : 'Ativar' }}
                                </button>
                            @endunless
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Convites --}}
    <section class="card">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Convites pendentes</h2>
            <p class="text-xs text-slate-500 dark:text-slate-400">Registro público é desabilitado: toda pessoa entra por convite.</p>
        </header>

        @can('create', \App\Models\Invitation::class)
            <form wire:submit="invite" class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-4 dark:border-slate-800">
                <div>
                    <label for="inviteName" class="label">Nome (opcional)</label>
                    <input id="inviteName" type="text" wire:model="inviteName" class="input">
                </div>
                <div>
                    <label for="inviteEmail" class="label">E-mail</label>
                    <input id="inviteEmail" type="email" wire:model="inviteEmail" class="input">
                    @error('inviteEmail')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="inviteRole" class="label">Papel</label>
                    <select id="inviteRole" wire:model="inviteRole" class="input">
                        @foreach ($this->invitableRoles() as $valor => $rotulo)
                            <option value="{{ $valor }}">{{ $rotulo }}</option>
                        @endforeach
                    </select>
                    @error('inviteRole')<p class="mt-1 text-sm text-rose-600">{{ $message }}</p>@enderror
                </div>
                <div class="flex items-end">
                    <button type="submit" class="btn-primary w-full">Enviar convite</button>
                </div>
            </form>
        @endcan

        @forelse ($this->pendingInvitations() as $convite)
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                <div class="min-w-0">
                    <p class="truncate text-sm font-medium">{{ $convite->name ?: $convite->email }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        {{ $convite->email }} · {{ $convite->role->label() }}
                        @if ($convite->client) · {{ $convite->client->name }} @endif
                        · expira {{ display_date($convite->expires_at, auth()->user()) }}
                    </p>
                </div>
                @can('revoke', $convite)
                    <button type="button" wire:click="revokeInvitation({{ $convite->getKey() }})"
                            wire:confirm="Cancelar o convite de {{ $convite->email }}?"
                            class="btn-secondary !px-3 !py-1.5 !text-xs">Cancelar</button>
                @endcan
            </div>
        @empty
            <x-empty-state title="Nenhum convite pendente">
                Preencha o e-mail e o papel acima para convidar alguém da equipe. O link chega por e-mail e vale por 7 dias.
            </x-empty-state>
        @endforelse
    </section>
</div>
