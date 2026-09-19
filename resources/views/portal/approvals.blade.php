@extends('layouts.portal')
@section('title', 'Aprovações')
@section('subtitle', 'O que precisa da sua decisão')

@section('content')
    <livewire:approvals.approval-queue :client="$client" :client-side="true" />
@endsection
