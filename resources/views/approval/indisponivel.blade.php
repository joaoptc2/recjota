@extends('layouts.approval')
@section('title', 'Link indisponível')

@section('content')
    <div class="card p-6 text-center">
        <p class="text-sm font-semibold">Este link não está mais válido</p>
        <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $motivo }}</p>
        <p class="mt-4 text-xs text-slate-500 dark:text-slate-400">
            Peça um link novo à equipe — nada do que você aprovou antes foi perdido.
        </p>
    </div>
@endsection
