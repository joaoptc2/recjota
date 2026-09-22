@component('mail::message')
# O Instagram de {{ $client->name }} precisa ser reconectado

A conta **{{ $account->handle() }}** perdeu o acesso e os próximos posts agendados
para ela **não serão publicados** até que alguém reconecte.

@component('mail::panel')
{{ $reason }}
@endcomponent

@component('mail::button', ['url' => $url])
Reconectar agora
@endcomponent

Abra a página do cliente, clique em **Reconectar** ao lado da conta e autorize
com um perfil que administre essa conta no Instagram. Leva menos de um minuto.

{{ config('agency.name') }}
@endcomponent
