@component('mail::message')
# {{ $titulo }}

{{ $mensagem }}

@component('mail::panel')
{{ $acao }}
@endcomponent

@component('mail::button', ['url' => $url])
Abrir a saúde do sistema
@endcomponent

Este aviso é enviado no máximo uma vez a cada 12 horas por tipo de problema.

{{ config('agency.name') }}
@endcomponent
