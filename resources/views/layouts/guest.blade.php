<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Entrar') — {{ config('agency.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-full items-center justify-center px-4 py-10">
    <main class="w-full max-w-sm">
        <div class="mb-8 text-center">
            <p class="text-2xl font-semibold tracking-tight">{{ config('agency.name') }}</p>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">@yield('subtitle', 'Gestão de conteúdo e aprovações')</p>
        </div>

        <div class="card p-6">
            @yield('content')
        </div>

        <p class="mt-6 text-center text-xs text-slate-400">
            Precisa de ajuda? <a class="underline" href="mailto:{{ config('agency.support_email') }}">{{ config('agency.support_email') }}</a>
        </p>
    </main>
</body>
</html>
