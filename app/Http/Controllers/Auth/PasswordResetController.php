<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Informe seu e-mail.', 'email.email' => 'Informe um e-mail válido.'],
        );

        Password::sendResetLink($request->only('email'));

        // Resposta sempre igual: não revelamos se o e-mail existe na base.
        return back()->with('status', 'Se este e-mail estiver cadastrado, enviamos um link para redefinir a senha.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PasswordReset) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => 'Não foi possível redefinir a senha. O link pode ter expirado — peça um novo.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Senha redefinida. Faça login com a nova senha.');
    }
}
