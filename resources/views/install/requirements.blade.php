@extends('layouts.bare')
@section('title', 'Instalação')

@section('body')
    <h1>Instalação do Recjota</h1>
    <p class="lead">Nenhum comando de terminal é necessário. Vamos conferir o ambiente primeiro.</p>

    @include('install._steps', ['atual' => 'requirements'])

    @if ($passou)
        <div class="alert ok">Ambiente pronto. Pode seguir.</div>
    @else
        <div class="alert bad">
            Alguns itens precisam de ajuste antes de continuar. Cada um abaixo diz onde resolver no hPanel.
        </div>
    @endif

    @foreach ($grupos as $grupo => $checks)
        <div class="card">
            <h2 style="margin-top:0">{{ $grupo }}</h2>
            <ul class="checks">
                @foreach ($checks as $check)
                    <li class="{{ $check['ok'] ? 'ok' : 'bad' }}">
                        <span class="mark" aria-hidden="true">{{ $check['ok'] ? '✓' : '✕' }}</span>
                        <span>
                            <span class="item">{{ $check['item'] }}</span>
                            <span class="detail"> — {{ $check['detalhe'] }}</span>
                            @unless ($check['ok'])
                                <div class="fix">Como resolver: {{ $check['comoResolver'] }}</div>
                            @endunless
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

    @if ($passou)
        <a class="btn" href="{{ route('install.environment') }}">Continuar</a>
    @else
        <a class="btn ghost" href="{{ route('install.requirements') }}">Conferir de novo</a>
    @endif
@endsection
