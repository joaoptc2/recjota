@extends('layouts.bare')
@section('title', 'Banco de dados')

@section('body')
    <h1>Estrutura do banco</h1>
    <p class="lead">Agora criamos as tabelas e a matriz de papéis. Leva alguns segundos.</p>

    @include('install._steps', ['atual' => 'database'])

    @if (session('status'))
        <div class="alert ok">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert bad">{{ $errors->first() }}</div>
    @endif

    <div class="card">
        @if ($conectado)
            <p style="margin-top:0">Conexão com o banco confirmada.</p>
        @else
            <p class="alert bad" style="margin-top:0">
                Ainda não foi possível conectar. <a href="{{ route('install.environment') }}">Volte um passo</a>
                e confira usuário, senha e nome do banco.
            </p>
        @endif

        @if ($jaMigrado)
            <p class="hint">As tabelas já existem. Rodar de novo é seguro: apenas o que estiver pendente é aplicado.</p>
        @endif

        <form method="POST" action="{{ route('install.database.run') }}">
            @csrf

            <label style="font-weight:400; display:flex; gap:8px; align-items:flex-start">
                <input type="checkbox" name="demo" value="1" style="width:auto; margin-top:3px">
                <span>
                    Incluir dados de demonstração
                    <span class="detail">— dois clientes e cinco usuários de exemplo, para conhecer o sistema.
                    Não marque numa instalação real.</span>
                </span>
            </label>

            <button type="submit" @disabled(! $conectado)>Criar a estrutura</button>
        </form>
    </div>
@endsection
