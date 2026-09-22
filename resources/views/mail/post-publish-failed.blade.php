@component('mail::message')
# Um post de {{ $client->name }} não foi publicado

O post agendado para
**{{ $post->scheduled_at !== null ? display_datetime($post->scheduled_at, $client) : 'sem data' }}**
na conta **{{ $conta }}** falhou na etapa *{{ $etapa }}* e o sistema **não vai tentar de novo sozinho**.

@component('mail::panel')
{{ $reason }}
@endcomponent

@component('mail::button', ['url' => $url])
Abrir o post
@endcomponent

Depois de corrigir a causa, reagende o post no painel para que ele volte para a fila.

{{ config('agency.name') }}
@endcomponent
