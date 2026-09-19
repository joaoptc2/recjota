@component('mail::message')
# {{ $approval->decided_by_name ?? 'O cliente' }} {{ $verbo }} um post

**Cliente:** {{ $client->name }}
**Post:** {{ \Illuminate\Support\Str::limit($post->caption ?: $post->type->label(), 120) }}
**Versão decidida:** {{ $approval->post_version }}

@if ($approval->decision_note)
@component('mail::panel')
{{ $approval->decision_note }}
@endcomponent
@endif

@component('mail::button', ['url' => route('painel.posts.show', $post)])
Abrir no painel
@endcomponent

{{ config('agency.name') }}
@endcomponent
