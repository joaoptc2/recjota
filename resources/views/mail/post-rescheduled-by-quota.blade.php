@component('mail::message')
# Post adiado: {{ $conta }} atingiu o limite do Instagram

O Instagram permite no máximo **{{ $limite }} publicações por conta a cada 24 horas**, e a conta
**{{ $conta }}** ({{ $client->name }}) já usou todas.

O post que sairia em **{{ $anterior }}** foi reagendado automaticamente para **{{ $novo }}**,
o primeiro horário em que há garantia de vaga.

@component('mail::button', ['url' => $url])
Ver o post
@endcomponent

Se esse horário não servir, abra o post e escolha outro — desde que respeite o limite da conta.

{{ config('agency.name') }}
@endcomponent
