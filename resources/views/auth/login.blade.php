@extends('layouts.guest')
@section('title', 'Entrar')

@section('content')
    @if (session('status'))
        <x-alert type="success" class="mb-4">{{ session('status') }}</x-alert>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" inputmode="email" autocomplete="username"
                   value="{{ old('email') }}" required autofocus class="input">
            @error('email')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">Senha</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required class="input">
            @error('password')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div class="flex items-center justify-between">
            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-300">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300 text-brand-600 focus:ring-brand-600">
                Manter conectado
            </label>
            <a href="{{ route('password.request') }}" class="text-sm font-medium text-brand-600 hover:underline">Esqueci a senha</a>
        </div>

        <button type="submit" class="btn-primary w-full">Entrar</button>
    </form>

    <p class="mt-6 text-center text-xs text-slate-500 dark:text-slate-400">
        O acesso é somente por convite. Se você é cliente e recebeu um link de aprovação por e-mail,
        pode aprovar direto por ele, sem senha.
    </p>
@endsection
