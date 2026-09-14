<?php

declare(strict_types=1);

use App\Http\Controllers\Agency\ClientController;
use App\Http\Controllers\Agency\DashboardController as AgencyDashboard;
use App\Http\Controllers\Agency\PostController as AgencyPostController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Portal\DashboardController as PortalDashboard;
use App\Http\Controllers\Portal\PostController as PortalPostController;
use Illuminate\Support\Facades\Route;

/*
|------------------------------------------------------------------------------
| Endpoint público de saúde (Seção 11.2)
|------------------------------------------------------------------------------
*/
Route::get('/health', HealthController::class)->name('health');

/*
|------------------------------------------------------------------------------
| Autenticação — registro público desabilitado (Seção 6.1)
|------------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', [LoginController::class, 'create'])->name('login');
    // O limite fino (5/min por e-mail + IP, com mensagem explicando o bloqueio)
    // vive no LoginRequest. Este throttle largo é só o teto por IP.
    Route::post('/entrar', [LoginController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('login.store');

    Route::get('/esqueci-a-senha', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('/esqueci-a-senha', [PasswordResetController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('/redefinir-senha/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('/redefinir-senha', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('password.update');

    Route::get('/convite/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/convite/{token}', [InvitationController::class, 'accept'])
        ->middleware('throttle:5,1')
        ->name('invitation.accept');
});

Route::post('/sair', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

// Sem closures em rota: o route:cache do deploy (Seção 11.2) não as suporta.
Route::get('/', HomeController::class)->name('home');

/*
|------------------------------------------------------------------------------
| Painel da agência — densidade alta (Seção 9.1)
|------------------------------------------------------------------------------
*/
Route::middleware(['auth', 'agency'])->prefix('painel')->name('painel.')->group(function (): void {
    Route::get('/', AgencyDashboard::class)->name('dashboard');

    Route::get('/clientes', [ClientController::class, 'index'])->name('clients.index');
    Route::get('/clientes/{client}', [ClientController::class, 'show'])->name('clients.show');

    Route::get('/posts/{post}', [AgencyPostController::class, 'show'])->name('posts.show');
});

/*
|------------------------------------------------------------------------------
| Portal do cliente — densidade baixa, sem nada técnico (Seção 6.10)
|------------------------------------------------------------------------------
*/
Route::middleware(['auth', 'client'])->prefix('portal')->name('portal.')->group(function (): void {
    Route::get('/', PortalDashboard::class)->name('dashboard');

    Route::get('/posts/{post}', [PortalPostController::class, 'show'])->name('posts.show');
});
