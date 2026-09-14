@extends('layouts.bare')
@section('title', 'Sem conexão com o banco')

@section('body')
    <h1>O sistema não conseguiu falar com o banco de dados</h1>
    <p class="lead">Nada foi perdido. Quase sempre é uma credencial trocada no arquivo de configuração.</p>

    <div class="card">
        <h2 style="margin-top:0">O que conferir, nesta ordem</h2>
        <ol style="padding-left:20px; margin:0">
            <li style="margin-bottom:10px">
                Abra <strong>hPanel › Bancos de Dados MySQL</strong> e confirme que o banco existe e está ativo.
            </li>
            <li style="margin-bottom:10px">
                No <strong>Gerenciador de Arquivos</strong>, edite o arquivo <code>.env</code> na pasta
                <code>app/</code> e confira <code>DB_DATABASE</code>, <code>DB_USERNAME</code> e
                <code>DB_PASSWORD</code>. Os nomes têm o prefixo <code>u........._</code>.
            </li>
            <li style="margin-bottom:10px">
                <code>DB_HOST</code> na Hostinger costuma ser <code>localhost</code>.
            </li>
            <li>
                Se você acabou de alterar o <code>.env</code>, apague os arquivos de
                <code>app/bootstrap/cache/</code> — a configuração em cache continua valendo até isso.
            </li>
        </ol>
    </div>

    <p class="hint">
        Esta tela substitui o erro genérico do servidor de propósito: sem acesso por SSH, uma mensagem
        vaga é uma parede.
    </p>
@endsection
