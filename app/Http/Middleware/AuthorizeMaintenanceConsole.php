<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Enums\RoleName;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Duas portas para o console de manutenção:
 *
 *  1. usuário autenticado com papel owner — o caminho normal;
 *  2. MAINTENANCE_TOKEN no .env — a porta de emergência para quando uma
 *     atualização quebrou o login e não há SSH para consertar.
 *
 * O token vem vazio de fábrica: sem ele configurado, só a porta 1 existe.
 */
class AuthorizeMaintenanceConsole
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->hasRole(RoleName::Owner->value)) {
            return $next($request);
        }

        $expected = (string) config('agency.maintenance_token');
        $provided = (string) ($request->input('token') ?? $request->header('X-Maintenance-Token', ''));

        if ($expected !== '' && strlen($expected) >= 32 && hash_equals($expected, $provided)) {
            return $next($request);
        }

        abort(404);
    }
}
