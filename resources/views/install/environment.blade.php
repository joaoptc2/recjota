@extends('layouts.bare')
@section('title', 'Configuração')

@section('body')
    <h1>Configuração</h1>
    <p class="lead">Estes dados vão para o arquivo <code>.env</code>. Você pode alterá-los depois.</p>

    @include('install._steps', ['atual' => 'environment'])

    @if ($errors->any())
        <div class="alert bad">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('install.environment.store') }}">
        @csrf

        <div class="card">
            <h2 style="margin-top:0">Identificação</h2>

            <label for="app_name">Nome da agência</label>
            <input id="app_name" name="app_name" type="text" required value="{{ old('app_name', $valores['app_name']) }}">

            <label for="app_url">Endereço do sistema</label>
            <input id="app_url" name="app_url" type="url" required value="{{ old('app_url', $valores['app_url']) }}">
            <p class="hint">Com https:// e sem barra no final. É o que entra nos links enviados por e-mail.</p>

            <label for="timezone">Fuso horário de exibição</label>
            <select id="timezone" name="timezone">
                @foreach (['America/Sao_Paulo', 'America/Manaus', 'America/Belem', 'America/Fortaleza', 'America/Cuiaba', 'America/Rio_Branco', 'America/Noronha', 'UTC'] as $tz)
                    <option value="{{ $tz }}" @selected(old('timezone', $valores['timezone']) === $tz)>{{ $tz }}</option>
                @endforeach
            </select>
            <p class="hint">O banco grava tudo em UTC. Este fuso é só para mostrar datas na tela.</p>
        </div>

        <div class="card">
            <h2 style="margin-top:0">Banco de dados MySQL</h2>
            <p class="hint" style="margin-bottom:6px">
                Crie o banco em hPanel › Bancos de Dados MySQL. Copie os nomes completos, com o prefixo
                <code>u........._</code>.
            </p>

            <div class="grid two">
                <div>
                    <label for="db_host">Servidor</label>
                    <input id="db_host" name="db_host" type="text" required value="{{ old('db_host', $valores['db_host']) }}">
                    <p class="hint">Na Hostinger costuma ser <code>localhost</code>.</p>
                </div>
                <div>
                    <label for="db_port">Porta</label>
                    <input id="db_port" name="db_port" type="number" required value="{{ old('db_port', $valores['db_port']) }}">
                </div>
            </div>

            <label for="db_database">Nome do banco</label>
            <input id="db_database" name="db_database" type="text" required value="{{ old('db_database', $valores['db_database']) }}">

            <div class="grid two">
                <div>
                    <label for="db_username">Usuário</label>
                    <input id="db_username" name="db_username" type="text" required value="{{ old('db_username', $valores['db_username']) }}">
                </div>
                <div>
                    <label for="db_password">Senha</label>
                    <input id="db_password" name="db_password" type="password" autocomplete="new-password">
                </div>
            </div>
        </div>

        <div class="card">
            <h2 style="margin-top:0">E-mail (opcional agora)</h2>
            <p class="hint" style="margin-bottom:6px">
                Sem isto o sistema funciona, mas não envia convite nem link de aprovação. Os dados estão em
                hPanel › E-mails › Configuração.
            </p>

            <div class="grid two">
                <div>
                    <label for="mail_host">Servidor SMTP</label>
                    <input id="mail_host" name="mail_host" type="text" value="{{ old('mail_host', 'smtp.hostinger.com') }}">
                </div>
                <div>
                    <label for="mail_port">Porta</label>
                    <input id="mail_port" name="mail_port" type="number" value="{{ old('mail_port', 465) }}">
                </div>
            </div>

            <div class="grid two">
                <div>
                    <label for="mail_username">Usuário</label>
                    <input id="mail_username" name="mail_username" type="text" value="{{ old('mail_username') }}">
                </div>
                <div>
                    <label for="mail_password">Senha</label>
                    <input id="mail_password" name="mail_password" type="password" autocomplete="new-password">
                </div>
            </div>

            <label for="mail_from_address">Remetente</label>
            <input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address') }}">
        </div>

        <button type="submit">Testar conexão e gravar</button>
    </form>
@endsection
