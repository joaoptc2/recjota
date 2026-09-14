@extends('layouts.app')
@section('title', 'Clientes')
@section('subtitle', 'Perfis atendidos pela agência')

@section('content')
    @if ($clients->isEmpty())
        <div class="card">
            <x-empty-state title="Nenhum cliente atribuído a você">
                Peça a um administrador para vincular você a um cliente. O vínculo é o que define
                tudo o que você enxerga no sistema.
            </x-empty-state>
        </div>
    @else
        {{-- Tabela no desktop, cartões no mobile (Seção 9.2) --}}
        <div class="card hidden overflow-hidden sm:block">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
                    <tr>
                        <th class="px-4 py-2.5 font-medium">Cliente</th>
                        <th class="px-4 py-2.5 font-medium">Status</th>
                        <th class="px-4 py-2.5 font-medium">Fuso</th>
                        <th class="px-4 py-2.5 text-right font-medium">Contas</th>
                        <th class="px-4 py-2.5 text-right font-medium">Posts</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @foreach ($clients as $client)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3">
                                <a href="{{ route('painel.clients.show', $client) }}" class="font-medium hover:underline">{{ $client->name }}</a>
                                @if ($client->settings?->approval_required)
                                    <span class="ml-2 text-xs text-slate-400">aprovação obrigatória</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                <x-badge :classes="$client->status->badgeClasses()">{{ $client->status->label() }}</x-badge>
                            </td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $client->displayTimezone() }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $client->social_accounts_count }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $client->posts_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="space-y-3 sm:hidden">
            @foreach ($clients as $client)
                <a href="{{ route('painel.clients.show', $client) }}" class="card block p-4">
                    <div class="flex items-start justify-between gap-2">
                        <p class="font-medium">{{ $client->name }}</p>
                        <x-badge :classes="$client->status->badgeClasses()">{{ $client->status->label() }}</x-badge>
                    </div>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                        {{ $client->social_accounts_count }} conta(s) · {{ $client->posts_count }} post(s) · {{ $client->displayTimezone() }}
                    </p>
                </a>
            @endforeach
        </div>
    @endif
@endsection
