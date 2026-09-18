@extends('layouts.bare')
@section('title', 'Administrador')

@section('body')
    <h1>Seu acesso</h1>
    <p class="lead">Este será o usuário <strong>proprietário</strong>, o único que pode excluir clientes.</p>

    @include('install._steps', ['atual' => 'administrator'])

    @if (session('status'))
        <div class="alert ok">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert bad">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('install.administrator.store') }}">
        @csrf

        <div class="card">
            <label for="name">Seu nome</label>
            <input id="name" name="name" type="text" required autofocus value="{{ old('name') }}">

            <label for="email">Seu e-mail</label>
            <input id="email" name="email" type="email" required value="{{ old('email') }}">

            <label for="password">Senha</label>
            <input id="password" name="password" type="password" required autocomplete="new-password">
            <p class="hint">No mínimo 10 caracteres.</p>

            <label for="password_confirmation">Confirme a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
        </div>

        <button type="submit">Concluir instalação</button>
    </form>

    <p class="hint" style="margin-top:20px">
        Depois de concluir, o instalador deixa de existir. Para reabri-lo seria preciso apagar o arquivo
        <code>storage/app/installed.lock</code> <em>e</em> esvaziar a tabela de usuários.
    </p>
@endsection
