@props(['atual'])
@php $passos = ['requirements' => 'Ambiente', 'environment' => 'Configuração', 'database' => 'Banco', 'administrator' => 'Administrador']; @endphp
<ol class="steps">
    @foreach ($passos as $chave => $rotulo)
        <li @if ($chave === $atual) aria-current="step" @endif>{{ $rotulo }}</li>
    @endforeach
</ol>
