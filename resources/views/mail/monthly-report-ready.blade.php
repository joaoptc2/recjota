@component('mail::message')
# Relatório de {{ $report->periodLabel() }} — {{ $client->name }}

O relatório mensal de resultados no Instagram está pronto. O PDF vai em anexo
e também fica disponível para download a qualquer momento.

@component('mail::button', ['url' => $url])
Ver relatórios
@endcomponent

Os números vêm direto da API do Instagram. Quando um dado não é informado pela
plataforma, o relatório mostra "indisponível" em vez de estimar.

{{ config('agency.name') }}
@endcomponent
