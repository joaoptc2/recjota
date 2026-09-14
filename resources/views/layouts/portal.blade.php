@php
    $user = auth()->user();
    $client = $client ?? $user->clients()->first();
    $primary = $client?->primaryColor() ?? config('agency.primary_color');
    $nav = [
        ['label' => 'Calendário', 'route' => null, 'icon' => $icons['calendar'], 'soon' => true],
        ['label' => 'Aprovações', 'route' => null, 'icon' => $icons['approvals'], 'soon' => true],
        ['label' => 'Arquivos', 'route' => null, 'icon' => $icons['files'], 'soon' => true],
        ['label' => 'Relatórios', 'route' => null, 'icon' => $icons['reports'], 'soon' => true],
        ['label' => 'Solicitações', 'route' => null, 'icon' => $icons['briefs'], 'soon' => true],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Portal') — {{ $client?->name ?? config('agency.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    {{-- White-label: a cor primária vem do cliente (Seção 6.10). --}}
    <style>:root { --portal-primary: {{ $primary }}; }</style>
</head>
<body class="min-h-full bg-white pb-bottomnav sm:pb-0 dark:bg-slate-950">
    <header class="border-b border-slate-200 dark:border-slate-800">
        <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4">
            <div class="flex items-center gap-3">
                @if ($client?->logo_path)
                    <img src="{{ Storage::url($client->logo_path) }}" alt="{{ $client->name }}" class="size-9 rounded-lg object-cover">
                @else
                    <span class="flex size-9 items-center justify-center rounded-lg text-sm font-semibold text-white" style="background: var(--portal-primary)">
                        {{ mb_substr($client?->name ?? '?', 0, 1) }}
                    </span>
                @endif
                <div>
                    <p class="text-sm font-semibold">{{ $client?->name ?? 'Seu perfil' }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $user->name }}</p>
                </div>
            </div>

            <div class="flex items-center gap-1">
                <button type="button" onclick="window.toggleTheme()" class="rounded-lg p-2 text-slate-400" aria-label="Alternar tema claro e escuro">{!! $icons['theme'] !!}</button>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg p-2 text-slate-400" aria-label="Sair">{!! $icons['logout'] !!}</button>
                </form>
            </div>
        </div>

        {{-- Menu horizontal apenas no desktop; no mobile fica na barra inferior. --}}
        <nav class="mx-auto hidden max-w-3xl gap-1 px-4 pb-2 sm:flex" aria-label="Navegação do portal">
            @foreach ($nav as $item)
                <x-nav-item :href="$item['route'] ? route($item['route']) : null" :icon="$item['icon']" :soon="$item['soon']">{{ $item['label'] }}</x-nav-item>
            @endforeach
        </nav>
    </header>

    <main id="conteudo" class="mx-auto max-w-3xl px-4 py-6">
        <h1 class="text-xl font-semibold tracking-tight">@yield('title', 'Seu painel')</h1>
        @hasSection('subtitle')
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">@yield('subtitle')</p>
        @endif

        @if (session('status'))
            <x-alert type="success" class="mt-4">{{ session('status') }}</x-alert>
        @endif

        <div class="mt-5">
            @yield('content')
        </div>
    </main>

    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-slate-200 bg-white sm:hidden dark:border-slate-800 dark:bg-slate-900" aria-label="Navegação inferior">
        @foreach (array_slice($nav, 0, 4) as $item)
            <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
               @if ($item['soon']) aria-disabled="true" @endif
               class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium {{ $item['soon'] ? 'text-slate-300 dark:text-slate-700' : 'text-slate-500' }}">
                {!! $item['icon'] !!}
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    @livewireScripts
</body>
</html>
