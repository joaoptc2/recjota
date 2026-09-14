@extends('layouts.guest')
@section('title', 'Aceitar convite')
@section('subtitle', $invitation->client?->name ?? config('agency.name'))

@section('content')
    <p class="mb-4 text-sm text-slate-600 dark:text-slate-300">
        Você foi convidado como <strong>{{ $invitation->role->label() }}</strong>. Escolha uma senha para
        ativar seu acesso.
    </p>

    <form method="POST" action="{{ route('invitation.accept', $token) }}" class="space-y-4">
        @csrf

        <div>
            <label class="label">E-mail</label>
            <input type="email" value="{{ $invitation->email }}" disabled class="input opacity-70">
        </div>

        <div>
            <label for="name" class="label">Seu nome</label>
            <input id="name" name="name" type="text" value="{{ old('name', $invitation->name) }}" required autofocus class="input">
            @error('name')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">Senha</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="input">
            @error('password')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirme a senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input">
        </div>

        <button type="submit" class="btn-primary w-full">Ativar meu acesso</button>
    </form>
@endsection
