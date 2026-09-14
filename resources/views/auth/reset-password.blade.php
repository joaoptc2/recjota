@extends('layouts.guest')
@section('title', 'Nova senha')

@section('content')
    <form method="POST" action="{{ route('password.update') }}" class="space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label for="email" class="label">E-mail</label>
            <input id="email" name="email" type="email" autocomplete="username"
                   value="{{ old('email', $email) }}" required class="input">
            @error('email')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="label">Nova senha</label>
            <input id="password" name="password" type="password" autocomplete="new-password" required class="input">
            @error('password')<p class="mt-1.5 text-sm text-rose-600">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="label">Confirme a nova senha</label>
            <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required class="input">
        </div>

        <button type="submit" class="btn-primary w-full">Redefinir senha</button>
    </form>
@endsection
