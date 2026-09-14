@extends('layouts.bare')
@section('title', 'Manutenção')

@section('body')
    <h1>Console de manutenção</h1>
    <p class="lead">
        O terminal que a hospedagem compartilhada não oferece. Só estes comandos existem — nada aqui aceita
        texto digitado.
    </p>

    @if ($cronParado)
        <div class="alert bad">
            O agendador não dá sinal desde
            {{ $batimento?->last_run_at ? display_datetime($batimento->last_run_at) : 'nunca' }}.
            Sem ele nada é publicado, nenhum e-mail sai e nenhum token é renovado.
            Confira o cron em hPanel › Avançado › Tarefas Cron.
        </div>
    @else
        <div class="alert ok">
            Agendador ativo. Último batimento: {{ display_datetime($batimento->last_run_at) }} (UTC).
        </div>
    @endif

    @if ($comandoExecutado)
        <div class="card">
            <h2 style="margin-top:0">{{ $comandoExecutado }}</h2>
            <pre>{{ $saida }}</pre>
        </div>
    @endif

    @foreach ($comandos as $chave => $comando)
        <div class="card">
            <h2 style="margin-top:0">{{ $comando['titulo'] }}</h2>
            <p class="hint" style="margin:0">{{ $comando['descricao'] }}</p>
            <form method="POST" action="{{ route('maintenance.run', $chave) }}">
                @csrf
                @if (! auth()->check())
                    <input type="hidden" name="token" value="{{ request('token') }}">
                @endif
                <button type="submit" class="small" style="margin-top:12px">Executar</button>
            </form>
        </div>
    @endforeach

    <p class="hint">
        Sequência típica depois de enviar uma nova versão do sistema:
        <strong>Atualizar o banco</strong> → <strong>Limpar os caches</strong> → <strong>Reconstruir os caches</strong>.
    </p>

    @auth
        <a class="btn ghost" href="{{ route('painel.dashboard') }}">Voltar ao painel</a>
    @endauth
@endsection
