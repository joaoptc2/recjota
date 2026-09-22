@php
    $user = auth()->user();
    $nav = [
        ['label' => 'Painel', 'route' => 'painel.dashboard', 'icon' => $icons['dashboard'], 'soon' => false],
        ['label' => 'Clientes', 'route' => 'painel.clients.index', 'icon' => $icons['clients'], 'soon' => false],
        ['label' => 'Calendário', 'route' => 'painel.calendar', 'icon' => $icons['calendar'], 'soon' => false],
        ['label' => 'Aprovações', 'route' => 'painel.approvals', 'icon' => $icons['approvals'], 'soon' => false],
        ['label' => 'Biblioteca', 'route' => 'painel.media', 'icon' => $icons['media'], 'soon' => false],
        ['label' => 'Tarefas', 'route' => 'painel.tasks', 'icon' => $icons['tasks'], 'soon' => false],
        ['label' => 'Relatórios', 'route' => 'painel.reports', 'icon' => $icons['reports'], 'soon' => false],
        ['label' => 'Integrações', 'route' => 'painel.integrations', 'icon' => $icons['integrations'], 'soon' => false],
        ['label' => 'Configurações', 'route' => 'painel.settings', 'icon' => $icons['settings'], 'soon' => false],
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Painel') — {{ config('agency.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-full pb-bottomnav lg:pb-0">
    <a href="#conteudo" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:shadow">
        Pular para o conteúdo
    </a>

    <div class="lg:flex">
        {{-- Sidebar fixa acima de 1024px (Seção 9.2) --}}
        <aside class="hidden w-64 shrink-0 border-r border-slate-200 bg-white lg:sticky lg:top-0 lg:block lg:h-screen dark:border-slate-800 dark:bg-slate-900">
            <div class="flex h-full flex-col p-4">
                <div class="px-2 py-3">
                    <p class="text-lg font-semibold tracking-tight">{{ config('agency.name') }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">Painel da agência</p>
                </div>

                <nav class="mt-4 flex-1 space-y-1" aria-label="Navegação principal">
                    @foreach ($nav as $item)
                        <x-nav-item
                            :href="$item['route'] ? route($item['route']) : null"
                            :active="$item['route'] && request()->routeIs($item['route'].'*')"
                            :icon="$item['icon']"
                            :soon="$item['soon']">{{ $item['label'] }}</x-nav-item>
                    @endforeach
                </nav>

                <div class="border-t border-slate-200 pt-4 dark:border-slate-800">
                    <p class="px-3 text-sm font-medium">{{ $user->name }}</p>
                    <p class="px-3 text-xs text-slate-500 dark:text-slate-400">{{ $user->primaryRole()?->label() ?? 'Equipe' }}</p>
                    <div class="mt-2 flex items-center gap-1">
                        <button type="button" onclick="window.toggleTheme()" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800" aria-label="Alternar tema claro e escuro">
                            {!! $icons['theme'] !!}
                        </button>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800">
                                {!! $icons['logout'] !!} Sair
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </aside>

        <div class="min-w-0 flex-1">
            {{-- Cabeçalho mobile --}}
            <header class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-200 bg-white/90 px-4 py-3 backdrop-blur lg:hidden dark:border-slate-800 dark:bg-slate-900/90">
                <div>
                    <p class="text-sm font-semibold">{{ config('agency.name') }}</p>
                    <p class="text-xs text-slate-500 dark:text-slate-400">@yield('title', 'Painel')</p>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" onclick="window.toggleTheme()" class="rounded-lg p-2 text-slate-500" aria-label="Alternar tema claro e escuro">{!! $icons['theme'] !!}</button>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="rounded-lg p-2 text-slate-500" aria-label="Sair">{!! $icons['logout'] !!}</button>
                    </form>
                </div>
            </header>

            <main id="conteudo" class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div class="hidden lg:block">
                    <h1 class="text-2xl font-semibold tracking-tight">@yield('title', 'Painel')</h1>
                    @hasSection('subtitle')
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">@yield('subtitle')</p>
                    @endif
                </div>

                @if (session('status'))
                    <x-alert type="success" class="mt-4">{{ session('status') }}</x-alert>
                @endif

                @if ($errors->any())
                    <x-alert type="error" class="mt-4">{{ $errors->first() }}</x-alert>
                @endif

                <div class="mt-4 lg:mt-6">
                    @yield('content')
                </div>
            </main>
        </div>
    </div>

    {{-- Navegação inferior abaixo de 640px: 4 ícones, nada mais (Seção 9.2) --}}
    <nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-slate-200 bg-white lg:hidden dark:border-slate-800 dark:bg-slate-900" aria-label="Navegação inferior">
        @foreach (collect($nav)->reject(fn ($i) => $i['soon'])->take(4) as $item)
            @php $isActive = $item['route'] && request()->routeIs($item['route'].'*'); @endphp
            <a href="{{ $item['route'] ? route($item['route']) : '#' }}"
               @if ($item['soon']) aria-disabled="true" @endif
               class="flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium
                      {{ $item['soon'] ? 'text-slate-300 dark:text-slate-700' : ($isActive ? 'text-brand-600 dark:text-brand-400' : 'text-slate-500 dark:text-slate-400') }}">
                {!! $item['icon'] !!}
                {{ $item['label'] }}
            </a>
        @endforeach
    </nav>

    @livewireScripts
</body>
</html>
