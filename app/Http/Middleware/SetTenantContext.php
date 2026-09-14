<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carrega o contexto de tenant a partir do usuário autenticado (Seção 4.1).
 * Roda depois do middleware de autenticação e antes de qualquer controller.
 */
class SetTenantContext
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->tenant->forUser($request->user());

        // Usuário do cliente fica travado no próprio cliente, sem seletor.
        if ($request->user()?->isClient()) {
            $ids = $request->user()->accessibleClientIds();

            if (count($ids) === 1) {
                $this->tenant->setActiveClient($ids[0]);
            }
        }

        return $next($request);
    }
}
