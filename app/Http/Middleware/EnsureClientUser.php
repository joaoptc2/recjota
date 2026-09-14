<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isClient()) {
            return redirect()->route('painel.dashboard');
        }

        return $next($request);
    }
}
