<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Installation;
use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Aplica nome/e-mail/cor salvos em Configurações sobre config('agency.*')
 * a cada requisição. Roda como middleware (e não no boot do provider) para
 * nunca tocar o banco antes do instalador ou durante uma queda do MySQL.
 */
class ApplyAgencySettings
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Installation::isInstalled()) {
            try {
                $this->settings->applyToConfig();
            } catch (Throwable) {
                // Banco indisponível: o .env continua valendo; a tela de erro
                // de banco (bootstrap/app.php) cuida do resto.
            }
        }

        return $next($request);
    }
}
