@extends('layouts.app')
@section('title', 'Novo post')
@section('subtitle', $client->name)

@section('content')
    @if ($clients->count() > 1)
        <form method="GET" class="card mb-4 flex flex-wrap items-end gap-3 p-4">
            <div>
                <label for="cliente" class="label">Cliente</label>
                <select id="cliente" name="cliente" class="input" onchange="this.form.submit()">
                    @foreach ($clients as $opcao)
                        <option value="{{ $opcao->ulid }}" @selected($opcao->is($client))>{{ $opcao->name }}</option>
                    @endforeach
                </select>
            </div>
            <p class="pb-2.5 text-xs text-slate-500 dark:text-slate-400">
                Trocar o cliente recomeça o rascunho: mídia e contas pertencem a um cliente só.
            </p>
        </form>
    @endif

    <livewire:posts.post-composer :client="$client" />
@endsection
