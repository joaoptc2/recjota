@php $primaria = $client?->primaryColor() ?? config('agency.primary_color'); @endphp
<!DOCTYPE html>
<html lang="pt-BR" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Aprovação') — {{ $client?->name ?? config('agency.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>:root { --portal-primary: {{ $primaria }}; }</style>
</head>
{{-- Mobile primeiro de verdade: esta tela é usada no celular, dentro do app
     de e-mail, muitas vezes em movimento (Seção 9.1). --}}
<body class="min-h-full bg-slate-50 dark:bg-slate-950">
    <header class="border-b border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div class="mx-auto flex max-w-lg items-center gap-3 px-4 py-3">
            @if ($client?->logo_path)
                <img src="{{ route('midia.logo', $client) }}" alt="{{ $client->name }}" class="size-8 rounded-lg object-cover">
            @else
                <span class="flex size-8 items-center justify-center rounded-lg text-sm font-semibold text-white" style="background: var(--portal-primary)">
                    {{ mb_substr($client?->name ?? '?', 0, 1) }}
                </span>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold">{{ $client?->name }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">@yield('subtitle', 'Aprovação de conteúdo')</p>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-lg px-4 py-5 pb-24">
        @if (session('status'))
            <x-alert type="success" class="mb-4">{{ session('status') }}</x-alert>
        @endif

        @if ($errors->any())
            <x-alert type="error" class="mb-4">{{ $errors->first() }}</x-alert>
        @endif

        @yield('content')
    </main>

    <footer class="pb-8 text-center text-xs text-slate-400">
        Enviado por {{ config('agency.name') }}
    </footer>
</body>
</html>
