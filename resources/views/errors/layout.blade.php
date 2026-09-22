@extends('layouts.bare')
@section('title', $titulo)

@section('body')
    <div class="card" role="alert">
        <p class="detail">Erro {{ $codigo }}</p>
        <h1>{{ $titulo }}</h1>
        <p class="lead">{{ $oQueAconteceu }}</p>
        <h2>O que fazer</h2>
        <p>{{ $oQueFazer }}</p>
        <a href="{{ $destino }}" class="btn">{{ $rotulo }}</a>
        @if (! empty($mensagem) && ! in_array($mensagem, ['This action is unauthorized.', 'Forbidden', 'Not Found'], true))
            <p class="hint" style="margin-top:16px">Detalhe: {{ $mensagem }}</p>
        @endif
    </div>
@endsection
