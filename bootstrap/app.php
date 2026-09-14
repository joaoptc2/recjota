<?php

use App\Http\Middleware\AuthorizeMaintenanceConsole;
use App\Http\Middleware\EnsureAgencyUser;
use App\Http\Middleware\EnsureClientUser;
use App\Http\Middleware\EnsureInstallerIsAvailable;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RedirectIfNotInstalled;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Sistema recém-enviado por FTP cai no instalador, não num erro
            // de conexão com o banco.
            RedirectIfNotInstalled::class,
            EnsureUserIsActive::class,
            SetTenantContext::class,
        ]);

        $middleware->alias([
            'agency' => EnsureAgencyUser::class,
            'client' => EnsureClientUser::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Portas de manutenção para hospedagem sem SSH.
            'installer' => EnsureInstallerIsAvailable::class,
            'maintenance-console' => AuthorizeMaintenanceConsole::class,
        ]);

        // Cabeçalhos de segurança (Seção 10) aplicados a toda resposta web.
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Sem SSH, um 500 genérico é uma parede. Falha de banco ganha uma tela
        // que diz o que conferir e onde (Seção 14: todo erro precisa dizer
        // qual é a próxima ação).
        $exceptions->render(function (QueryException|PDOException $e, Request $request) {
            if (config('app.debug') || $request->expectsJson()) {
                return null;
            }

            return response()->view('errors.database', status: 503);
        });
    })->create();
