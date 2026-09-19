@component('mail::message')
# Um post espera por você

@if ($saudacao)
Olá, {{ \Illuminate\Support\Str::before($saudacao, ' ') }}.
@endif

A equipe preparou um conteúdo para **{{ $client->name }}** e precisa do seu aval.

@component('mail::panel')
**{{ $post->type->label() }}**
{{ \Illuminate\Support\Str::limit($post->caption ?: 'Sem legenda.', 180) }}

@if ($post->scheduled_at)
Previsto para {{ display_datetime($post->scheduled_at, $client) }}.
@endif
@endcomponent

@component('mail::button', ['url' => $url])
Ver e aprovar
@endcomponent

Não precisa de senha: o botão abre direto a tela de aprovação.

@if ($approval->due_at)
Se puder responder até **{{ display_datetime($approval->due_at, $client) }}**, mantemos o calendário no lugar.
@endif

Se preferir ajustes, dá para pedir por essa mesma tela — a equipe recebe o seu recado com suas palavras.

Obrigado,
{{ config('agency.name') }}

@slot('subcopy')
Este link é pessoal e expira em {{ display_datetime($approval->due_at ?? now()->addDays(7), $client) }}. Se o post mudar, ele deixa de valer e você recebe um novo.
@endslot
@endcomponent
