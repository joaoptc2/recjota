<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança (Seção 10) em toda resposta web.
 *
 * O CSP mantém 'unsafe-inline' e 'unsafe-eval' em script-src porque o
 * Livewire 3 e o Alpine (empacotado com ele) dependem de expressões inline;
 * o ganho real vem das outras diretivas: nada de objeto/plugin, formulários
 * só para nós e para os provedores OAuth, frames só do Google Picker,
 * imagens só de origens conhecidas e nenhum frame-ancestor além do próprio
 * site. O Picker da Google precisa de frame-src explícito (Seção 10).
 */
class SecurityHeaders
{
    /** @var array<string, array<int, string>> */
    public const CSP = [
        'default-src' => ["'self'"],
        'base-uri' => ["'self'"],
        'object-src' => ["'none'"],
        'frame-ancestors' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'", "'unsafe-eval'", 'https://apis.google.com', 'https://accounts.google.com'],
        'style-src' => ["'self'", "'unsafe-inline'"],
        'img-src' => ["'self'", 'data:', 'blob:', 'https:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'", 'https://apis.google.com', 'https://www.googleapis.com', 'https://accounts.google.com', 'https://docs.google.com'],
        'frame-src' => ["'self'", 'https://docs.google.com', 'https://accounts.google.com', 'https://content.googleapis.com', 'https://apis.google.com'],
        'form-action' => ["'self'", 'https://accounts.google.com', 'https://login.microsoftonline.com', 'https://www.instagram.com', 'https://api.instagram.com'],
        'upgrade-insecure-requests' => [],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::policy($request->secure()));
        }

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    public static function policy(bool $https = true): string
    {
        $diretivas = [];

        foreach (self::CSP as $nome => $fontes) {
            // Em HTTP (desenvolvimento local) o upgrade quebraria o próprio site.
            if ($nome === 'upgrade-insecure-requests' && ! $https) {
                continue;
            }

            $diretivas[] = trim($nome.' '.implode(' ', $fontes));
        }

        return implode('; ', $diretivas);
    }
}
