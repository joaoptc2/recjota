@extends('layouts.app')
@section('title', 'Calendário')
@section('subtitle', $client?->name ?? 'Todos os clientes')

@section('content')
    @if ($clients->count() > 1)
        <form method="GET" class="mb-4">
            <label for="cliente" class="sr-only">Cliente</label>
            <select id="cliente" name="cliente" class="input !w-auto" onchange="this.form.submit()">
                <option value="">Todos os clientes</option>
                @foreach ($clients as $opcao)
                    <option value="{{ $opcao->ulid }}" @selected($client && $opcao->is($client))>{{ $opcao->name }}</option>
                @endforeach
            </select>
        </form>
    @endif

    <livewire:calendar.editorial-calendar :client="$client" />
@endsection
