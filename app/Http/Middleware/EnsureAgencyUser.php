<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Separa fisicamente o painel da agência do portal do cliente (Seção 6.10). */
class EnsureAgencyUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isAgency()) {
            return redirect()->route('portal.dashboard');
        }

        return $next($request);
    }
}
