@php
    $s = $this->summary();
    $delta = $s->followersDelta();
    $reachChange = $s->reachChangePercent();
    $engChange = $s->engagementChangePoints();
    $reachLines = $this->polyline($s->daily, 'reach');
    $followerLines = $this->polyline($s->daily, 'followers');
    $podeGerar = ! $portal && auth()->user()->can('create', \App\Models\Report::class);
@endphp

<div>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <label for="periodo-{{ $this->getId() }}" class="label">Período</label>
            <select id="periodo-{{ $this->getId() }}" wire:model.live="period" class="input !w-auto">
                @foreach (self::PERIODS as $valor => $rotulo)
                    <option value="{{ $valor }}">{{ $rotulo }}</option>
                @endforeach
            </select>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $this->periodLabel() }} · fuso {{ $client->displayTimezone() }}</p>
        </div>

        @unless ($portal)
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('painel.reports.export', ['client' => $client, 'dias' => $period === 'mes' ? 30 : $period, 'mes' => $period === 'mes' ? now()->format('Y-m') : null]) }}" class="btn-secondary !px-3 !py-1.5 !text-xs">
                    Exportar CSV
                </a>
                @if ($podeGerar)
                    <form method="POST" action="{{ route('painel.reports.generate', $client) }}" class="flex items-center gap-2">
                        @csrf
                        <label for="mes-{{ $this->getId() }}" class="sr-only">Mês do PDF</label>
                        <select id="mes-{{ $this->getId() }}" name="mes" class="input !w-auto !py-1.5 !text-xs">
                            @foreach ($this->monthOptions() as $valor => $rotulo)
                                <option value="{{ $valor }}">{{ $rotulo }}</option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn-primary !px-3 !py-1.5 !text-xs">Gerar PDF</button>
                    </form>
                @endif
            </div>
        @endunless
    </div>

    @error('mes')<p class="mt-2 text-sm text-rose-600 dark:text-rose-300">{{ $message }}</p>@enderror

    <div wire:loading wire:target="period" class="mt-3 text-sm text-slate-500 dark:text-slate-400">Calculando…</div>

    @if (! $s->hasAnyData)
        <div class="card mt-4">
            <x-empty-state title="Ainda não há métricas para este período">
                @if ($portal)
                    Os números do Instagram chegam aqui todo dia de madrugada, a partir do primeiro dia com a conta conectada.
                @else
                    A coleta roda diariamente às 04:10 UTC para contas conectadas. Se a conta acabou de ser conectada, os primeiros
                    dados aparecem amanhã; se já faz mais tempo, confira em Integrações se a conta está saudável.
                @endif
            </x-empty-state>
        </div>
    @else
        <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-stat label="Seguidores" :value="$this->formatNumber($s->followersEnd)"
                    :hint="$delta === null ? 'variação indisponível' : ($delta >= 0 ? '+' : '').number_format($delta, 0, ',', '.').' no período'"
                    :tone="$delta === null ? 'default' : ($delta >= 0 ? 'good' : 'alert')" />
            <x-stat label="Alcance" :value="$this->formatNumber($s->reach)"
                    :hint="$reachChange === null ? 'sem base de comparação' : $this->formatPercent($reachChange, true).' vs. período anterior'"
                    :tone="$reachChange === null ? 'default' : ($reachChange >= 0 ? 'good' : 'warn')" />
            <x-stat label="Impressões" :value="$this->formatNumber($s->impressions)" hint="soma dos dias com dado" />
            <x-stat label="Engajamento médio" :value="$this->formatPercent($s->engagementRate)"
                    :hint="$s->postsMeasured.' de '.$s->postsPublished.' posts medidos'.($engChange !== null ? ' · '.($engChange >= 0 ? '+' : '').number_format($engChange, 2, ',', '.').' p.p.' : '')" />
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="card p-4">
                <h2 class="text-sm font-semibold">Alcance por dia</h2>
                @if ($reachLines === [])
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Alcance diário indisponível neste período.</p>
                @else
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="mt-3 h-32 w-full" role="img" aria-label="Alcance por dia">
                        <line x1="0" y1="95" x2="100" y2="95" stroke="currentColor" stroke-opacity="0.15" stroke-width="0.5" />
                        @foreach ($reachLines as $pontos)
                            <polyline points="{{ $pontos }}" fill="none" stroke="{{ $client->primaryColor() }}" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        @endforeach
                    </svg>
                    <p class="mt-1 flex justify-between text-[11px] text-slate-400"><span>{{ display_date($s->from, $client) }}</span><span>{{ display_date($s->to, $client) }}</span></p>
                @endif
            </section>

            <section class="card p-4">
                <h2 class="text-sm font-semibold">Seguidores</h2>
                @if ($followerLines === [])
                    <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Contagem de seguidores indisponível neste período.</p>
                @else
                    <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="mt-3 h-32 w-full" role="img" aria-label="Seguidores por dia">
                        <line x1="0" y1="95" x2="100" y2="95" stroke="currentColor" stroke-opacity="0.15" stroke-width="0.5" />
                        @foreach ($followerLines as $pontos)
                            <polyline points="{{ $pontos }}" fill="none" stroke="#059669" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                        @endforeach
                    </svg>
                    <p class="mt-1 flex justify-between text-[11px] text-slate-400"><span>{{ $this->formatNumber($s->followersStart) }}</span><span>{{ $this->formatNumber($s->followersEnd) }}</span></p>
                @endif
            </section>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="card">
                <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <h2 class="text-sm font-semibold">Melhores posts do período</h2>
                </header>
                @forelse ($s->topPosts as $post)
                    @php $m = $post->getRelation('latestMetric'); $capa = $post->postMedia->first()?->mediaAsset; @endphp
                    <div class="flex items-center gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                        <span class="size-12 shrink-0 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                            @if ($capa)
                                <img src="{{ $capa->thumbUrl() }}" alt="{{ $post->postMedia->first()?->alt_text ?? $capa->filename }}" loading="lazy" class="size-full object-cover">
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">{{ Str::limit($post->caption ?: 'Sem legenda', 70) }}</p>
                            <p class="text-xs text-slate-500 dark:text-slate-400">
                                {{ display_date($post->published_at, $client) }} · {{ $post->type->label() }}
                                @if ($post->external_permalink)
                                    · <a href="{{ $post->external_permalink }}" target="_blank" rel="noopener" class="underline">ver no Instagram</a>
                                @endif
                            </p>
                        </div>
                        <dl class="grid shrink-0 grid-cols-2 gap-x-3 text-right text-xs">
                            <dt class="text-slate-500 dark:text-slate-400">alcance</dt>
                            <dd class="font-medium">{{ $this->formatNumber($m?->reach) }}</dd>
                            <dt class="text-slate-500 dark:text-slate-400">engaj.</dt>
                            <dd class="font-medium">{{ $this->formatPercent($m?->engagement_rate !== null ? (float) $m->engagement_rate : null) }}</dd>
                        </dl>
                    </div>
                @empty
                    <x-empty-state title="Nenhum post medido no período">
                        Os posts publicados entram aqui no dia seguinte à coleta de métricas.
                    </x-empty-state>
                @endforelse
            </section>

            <section class="card overflow-x-auto">
                <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
                    <h2 class="text-sm font-semibold">Mês a mês</h2>
                </header>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500 dark:text-slate-400">
                        <tr>
                            <th class="px-4 py-2 font-medium">Mês</th>
                            <th class="px-2 py-2 text-right font-medium">Posts</th>
                            <th class="px-2 py-2 text-right font-medium">Alcance</th>
                            <th class="px-2 py-2 text-right font-medium">Seguidores</th>
                            <th class="px-4 py-2 text-right font-medium">Engaj.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($s->months as $mes)
                            <tr class="border-t border-slate-100 dark:border-slate-800">
                                <td class="px-4 py-2">{{ $mes['label'] }}</td>
                                <td class="px-2 py-2 text-right">{{ $mes['posts'] }}</td>
                                <td class="px-2 py-2 text-right">{{ $this->formatNumber($mes['reach']) }}</td>
                                <td class="px-2 py-2 text-right">{{ $this->formatNumber($mes['followers']) }}</td>
                                <td class="px-4 py-2 text-right">{{ $this->formatPercent($mes['engagement']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </section>
        </div>
    @endif

    <section class="card mt-4">
        <header class="border-b border-slate-200 px-4 py-3 dark:border-slate-800">
            <h2 class="text-sm font-semibold">Relatórios em PDF</h2>
        </header>
        @forelse ($this->reports() as $report)
            <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 last:border-0 dark:border-slate-800">
                <div class="min-w-0">
                    <p class="text-sm font-medium">{{ $report->periodLabel() }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        gerado {{ display_datetime($report->generated_at, $client) }}
                        @if ($report->sent_at) · enviado por e-mail @endif
                    </p>
                </div>
                <a href="{{ route('reports.download', $report) }}" class="btn-secondary !px-3 !py-1.5 !text-xs">Baixar PDF</a>
            </div>
        @empty
            <x-empty-state title="Nenhum relatório gerado ainda">
                @if ($portal)
                    O relatório do mês chega por e-mail no dia 1 e fica guardado aqui.
                @else
                    Escolha o mês acima e clique em "Gerar PDF". No dia 1 de cada mês o sistema gera e envia sozinho.
                @endif
            </x-empty-state>
        @endforelse
    </section>
</div>
