@component('mail::message')
# Você foi convidado para o {{ $agencia }}

@if ($convidadoPor)
**{{ $convidadoPor }}** convidou você
@else
Você foi convidado
@endif
para entrar como **{{ $invitation->role->label() }}**@if ($invitation->client) no cliente **{{ $invitation->client->name }}**@endif.

@component('mail::button', ['url' => $url])
Aceitar o convite
@endcomponent

O link é pessoal e vale até {{ display_datetime($invitation->expires_at, $invitation->client) }}.
Se você não esperava este convite, ignore este e-mail: nada acontece sem o aceite.

{{ $agencia }}
@endcomponent
