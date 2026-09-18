<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * O instalador web só existe enquanto o sistema não está instalado. Depois
 * disso ele some — não fica um caminho paralelo de criação de administrador.
 */
class EnsureInstallerIsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Installation::isAvailable(), 404);

        return $next($request);
    }
}
