@extends('layouts.app')
@section('title', 'Relatórios')
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

    @if (session('status'))
        <x-alert type="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <livewire:reports.metrics-dashboard :client="$client" :key="'metricas-'.$client->getKey()" />
@endsection
