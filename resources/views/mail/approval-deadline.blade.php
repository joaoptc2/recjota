@component('mail::message')
# Faltam poucas horas

O prazo para decidir sobre este post de **{{ $client->name }}** termina em
**{{ display_datetime($approval->due_at, $client) }}**.

@component('mail::panel')
{{ \Illuminate\Support\Str::limit($post->caption ?: $post->type->label(), 180) }}
@endcomponent

@component('mail::button', ['url' => $url])
Decidir agora
@endcomponent

Um toque resolve. Se precisar de mais tempo, responda este e-mail que a equipe reagenda.

{{ config('agency.name') }}
@endcomponent
