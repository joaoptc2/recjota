@extends('layouts.portal')
@section('title', 'Relatórios')
@section('subtitle', 'Como o perfil está indo')

@section('content')
    @if ($client === null)
        <div class="card">
            <x-empty-state title="Nenhum perfil vinculado à sua conta">
                Peça à agência para vincular seu usuário ao cliente certo.
            </x-empty-state>
        </div>
    @else
        <livewire:reports.metrics-dashboard :client="$client" :portal="true" :key="'metricas-portal-'.$client->getKey()" />
    @endif
@endsection
