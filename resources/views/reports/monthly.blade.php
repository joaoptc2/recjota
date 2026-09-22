@php
    $fmt = fn (?int $v) => $v === null ? 'indisponível' : number_format($v, 0, ',', '.');
    $pct = fn (?float $v, bool $sinal = false) => $v === null ? 'indisponível' : (($sinal && $v > 0 ? '+' : '').number_format($v, 1, ',', '.').'%');
    $delta = $summary->followersDelta();
    $mesAnterior = collect($summary->months)->firstWhere('month', $month->copy()->subMonthNoOverflow()->format('Y-m'));
    $mesAtual = collect($summary->months)->firstWhere('month', $month->format('Y-m'));
    $semanas = collect($summary->daily)->chunk(7);
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>Relatório {{ $month->locale('pt_BR')->translatedFormat('F/Y') }} — {{ $client->name }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2937; line-height: 1.45; }
        h1 { font-size: 20px; margin: 0; color: {{ $primary }}; }
        h2 { font-size: 13px; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 2px solid {{ $primary }}; }
        .header { border-bottom: 3px solid {{ $primary }}; padding-bottom: 10px; margin-bottom: 14px; }
        .header table { width: 100%; }
        .logo { height: 44px; }
        .muted { color: #6b7280; }
        .kpis { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 0 -6px; }
        .kpi { width: 25%; background: #f3f4f6; border-radius: 6px; padding: 10px; vertical-align: top; }
        .kpi .label { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .kpi .value { font-size: 18px; font-weight: bold; margin-top: 2px; }
        .kpi .hint { font-size: 9px; color: #6b7280; margin-top: 2px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; font-size: 9px; text-transform: uppercase; color: #6b7280; padding: 4px 6px; border-bottom: 1px solid #e5e7eb; }
        table.data td { padding: 5px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        table.data td.num, table.data th.num { text-align: right; }
        .footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 9px; color: #9ca3af; text-align: center; }
        .up { color: #059669; } .down { color: #dc2626; }
    </style>
</head>
<body>
    <div class="footer">
        Relatório gerado por {{ $agency }} em {{ display_datetime($generatedAt, $client) }} · Dados fornecidos pela API do Instagram. "Indisponível" = a plataforma não informou o dado.
    </div>

    <div class="header">
        <table>
            <tr>
                <td>
                    <h1>{{ $client->name }}</h1>
                    <div class="muted">Resultados no Instagram · {{ ucfirst($month->locale('pt_BR')->translatedFormat('F \d\e Y')) }}</div>
                </td>
                <td style="text-align: right; vertical-align: middle;">
                    @if ($logo)
                        <img src="{{ $logo }}" alt="{{ $client->name }}" class="logo">
                    @else
                        <div style="font-weight: bold; color: {{ $primary }}; font-size: 14px;">{{ $agency }}</div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    @if (! $summary->hasAnyData)
        <p>Não há métricas coletadas para este mês. A coleta diária começa no dia seguinte à conexão da conta do Instagram.</p>
    @else
        <h2>Visão geral do mês</h2>
        <table class="kpis">
            <tr>
                <td class="kpi">
                    <div class="label">Seguidores</div>
                    <div class="value">{{ $fmt($summary->followersEnd) }}</div>
                    <div class="hint">
                        @if ($delta === null) variação indisponível
                        @else <span class="{{ $delta >= 0 ? 'up' : 'down' }}">{{ $delta >= 0 ? '+' : '' }}{{ number_format($delta, 0, ',', '.') }}</span> no mês
                        @endif
                    </div>
                </td>
                <td class="kpi">
                    <div class="label">Alcance</div>
                    <div class="value">{{ $fmt($summary->reach) }}</div>
                    <div class="hint">
                        @php $rc = $summary->reachChangePercent(); @endphp
                        @if ($rc === null) sem base de comparação
                        @else <span class="{{ $rc >= 0 ? 'up' : 'down' }}">{{ $pct($rc, true) }}</span> vs. mês anterior
                        @endif
                    </div>
                </td>
                <td class="kpi">
                    <div class="label">Impressões</div>
                    <div class="value">{{ $fmt($summary->impressions) }}</div>
                    <div class="hint">soma dos dias com dado</div>
                </td>
                <td class="kpi">
                    <div class="label">Engajamento médio</div>
                    <div class="value">{{ $pct($summary->engagementRate) }}</div>
                    <div class="hint">{{ $summary->postsMeasured }} de {{ $summary->postsPublished }} posts medidos</div>
                </td>
            </tr>
        </table>

        <h2>Comparativo com o mês anterior</h2>
        <table class="data">
            <thead>
                <tr><th>Indicador</th><th class="num">{{ $mesAnterior['label'] ?? 'Mês anterior' }}</th><th class="num">{{ $mesAtual['label'] ?? $month->format('m/Y') }}</th></tr>
            </thead>
            <tbody>
                <tr><td>Posts publicados</td><td class="num">{{ $mesAnterior['posts'] ?? 'indisponível' }}</td><td class="num">{{ $summary->postsPublished }}</td></tr>
                <tr><td>Alcance</td><td class="num">{{ $fmt($mesAnterior['reach'] ?? null) }}</td><td class="num">{{ $fmt($summary->reach) }}</td></tr>
                <tr><td>Seguidores no fim do mês</td><td class="num">{{ $fmt($mesAnterior['followers'] ?? null) }}</td><td class="num">{{ $fmt($summary->followersEnd) }}</td></tr>
                <tr><td>Visitas ao perfil</td><td class="num">indisponível</td><td class="num">{{ $fmt($summary->profileViews) }}</td></tr>
                <tr><td>Cliques no site</td><td class="num">indisponível</td><td class="num">{{ $fmt($summary->websiteClicks) }}</td></tr>
                <tr><td>Engajamento médio</td><td class="num">{{ $pct($mesAnterior['engagement'] ?? null) }}</td><td class="num">{{ $pct($summary->engagementRate) }}</td></tr>
            </tbody>
        </table>

        <h2>Melhores posts do mês</h2>
        @if ($summary->topPosts->isEmpty())
            <p class="muted">Nenhum post publicado neste mês teve métricas coletadas.</p>
        @else
            <table class="data">
                <thead>
                    <tr><th>Data</th><th>Post</th><th class="num">Alcance</th><th class="num">Curtidas</th><th class="num">Coment.</th><th class="num">Salvos</th><th class="num">Engaj.</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary->topPosts as $post)
                        @php $m = $post->getRelation('latestMetric'); @endphp
                        <tr>
                            <td>{{ display_date($post->published_at, $client) }}</td>
                            <td>{{ Str::limit($post->caption ?: 'Sem legenda', 80) }}<br><span class="muted">{{ $post->type->label() }} · {{ $post->socialAccount?->handle() }}</span></td>
                            <td class="num">{{ $fmt($m?->reach) }}</td>
                            <td class="num">{{ $fmt($m?->likes) }}</td>
                            <td class="num">{{ $fmt($m?->comments) }}</td>
                            <td class="num">{{ $fmt($m?->saves) }}</td>
                            <td class="num">{{ $pct($m?->engagement_rate !== null ? (float) $m->engagement_rate : null) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h2>Alcance semana a semana</h2>
        <table class="data">
            <thead><tr><th>Semana</th><th class="num">Alcance</th><th class="num">Seguidores no fim</th></tr></thead>
            <tbody>
                @foreach ($semanas as $semana)
                    @php
                        $alcances = $semana->pluck('reach')->filter(fn ($v) => $v !== null);
                        $ultimoSeg = $semana->pluck('followers')->filter(fn ($v) => $v !== null)->last();
                    @endphp
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($semana->first()['date'])->format('d/m') }} a {{ \Illuminate\Support\Carbon::parse($semana->last()['date'])->format('d/m') }}</td>
                        <td class="num">{{ $alcances->isEmpty() ? 'indisponível' : number_format((int) $alcances->sum(), 0, ',', '.') }}</td>
                        <td class="num">{{ $fmt($ultimoSeg) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
