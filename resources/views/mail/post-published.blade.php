@component('mail::message')
# Post publicado em {{ $conta }}

O post de **{{ $client->name }}** agendado para
{{ $post->scheduled_at !== null ? display_datetime($post->scheduled_at, $client) : 'sem data' }}
já está no ar.

@if ($permalink)
@component('mail::button', ['url' => $permalink])
Ver no Instagram
@endcomponent
@else
O link público ainda não foi disponibilizado pelo Instagram; ele aparece no painel assim que existir.
@endif

@component('mail::button', ['url' => $url, 'color' => 'primary'])
Abrir no painel
@endcomponent

{{ config('agency.name') }}
@endcomponent
