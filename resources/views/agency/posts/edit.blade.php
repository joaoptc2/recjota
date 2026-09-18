@extends('layouts.app')
@section('title', 'Editar post')
@section('subtitle', $post->client->name)

@section('content')
    @if (! $post->status->isEditable())
        <x-alert type="error" class="mb-4">
            Este post está {{ mb_strtolower($post->status->label()) }} e não pode mais ser editado.
        </x-alert>
    @elseif (in_array($post->status, [\App\Support\Enums\PostStatus::Approved, \App\Support\Enums\PostStatus::Scheduled], true))
        <x-alert type="info" class="mb-4">
            Este post já foi aprovado. Qualquer alteração cria uma versão nova, devolve o post para o
            cliente e invalida os links de aprovação já enviados.
        </x-alert>
    @endif

    <livewire:posts.post-composer :client="$post->client" :post="$post" />
@endsection
