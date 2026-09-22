@component('mail::message')
# O {{ $connection->provider->label() }} de {{ $client?->name ?? 'um cliente' }} precisa ser reconectado

A conexão com a conta **{{ $connection->label() }}** perdeu o acesso. Posts que usam
arquivos dessa nuvem **não serão publicados** até que alguém reconecte.

@component('mail::panel')
{{ $reason }}
@endcomponent

@component('mail::button', ['url' => $url])
Reconectar agora
@endcomponent

Abra a página do cliente, clique em **Reconectar** ao lado da conexão e autorize
com a mesma conta. Os arquivos já importados continuam na biblioteca.

{{ config('agency.name') }}
@endcomponent
