@extends('layouts.guest')
@section('title', 'Recuperar senha')
@section('subtitle', 'Enviaremos um link para você criar uma nova senha')

@section('content')
    @if (session('status'))
        <x-alert type="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username"
                   value="{{ old('email') }}" required autofocus class="input">
            @error('email')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <button type="submit" class="btn-primary w-full">Enviar link</button>
        <a href="{{ route('login') }}" class="btn-secondary w-full">Voltar ao login</a>
    </form>
@endsection
