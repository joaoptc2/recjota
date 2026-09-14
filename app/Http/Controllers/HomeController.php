<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Encaminha cada usuário para a sua área; visitante vai para o login. */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        return redirect()->route($user->isAgency() ? 'painel.dashboard' : 'portal.dashboard');
    }
}
