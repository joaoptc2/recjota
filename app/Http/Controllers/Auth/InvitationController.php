<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

/**
 * Registro público é desabilitado: todo usuário entra por convite com papel
 * pré-definido (Seção 6.1).
 */
class InvitationController extends Controller
{
    public function show(string $token): View
    {
        $invitation = $this->resolve($token);

        return view('auth.invitation', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->resolve($token);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ], [
            'name.required' => 'Informe seu nome.',
            'password.required' => 'Escolha uma senha.',
            'password.confirmed' => 'A confirmação da senha não confere.',
        ]);

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $invitation->email,
                'password' => $validated['password'],
                'type' => $invitation->type,
                'timezone' => $invitation->client?->displayTimezone() ?? config('agency.default_timezone'),
                'is_active' => true,
            ]);

            $user->assignRole($invitation->role->value);

            if ($invitation->client_id !== null) {
                $user->clients()->attach($invitation->client_id, [
                    'role' => $invitation->role->value,
                    'is_primary_contact' => false,
                ]);
            }

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->getKey(),
            ])->save();

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();
        app(TenantContext::class)->reset()->forUser($user);

        return redirect()->route($user->isAgency() ? 'painel.dashboard' : 'portal.dashboard');
    }

    private function resolve(string $token): Invitation
    {
        $invitation = Invitation::where('token', Invitation::hashToken($token))->first();

        abort_if($invitation === null || ! $invitation->isUsable(), 404);

        return $invitation;
    }
}
