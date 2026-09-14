<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sistema recém-enviado por FTP cai direto no instalador, em vez de mostrar
 * um erro de conexão com o banco que ninguém sabe interpretar.
 */
class RedirectIfNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled() || $request->routeIs('install.*', 'health')) {
            return $next($request);
        }

        if (! Installation::isAvailable()) {
            return $next($request);
        }

        return redirect()->route('install.requirements');
    }
}
