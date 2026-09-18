@extends('layouts.app')
@section('title', 'Biblioteca')
@section('subtitle', $client->name)

@section('content')
    @if ($clients->count() > 1)
        <form method="GET" class="mb-4">
            <label for="cliente" class="sr-only">Cliente</label>
            <select id="cliente" name="cliente" class="input !w-auto" onchange="this.form.submit()">
                @foreach ($clients as $opcao)
                    <option value="{{ $opcao->ulid }}" @selected($opcao->is($client))>{{ $opcao->name }}</option>
                @endforeach
            </select>
        </form>
    @endif

    <div class="card p-4">
        <livewire:media.media-library :client="$client" />
    </div>
@endsection
