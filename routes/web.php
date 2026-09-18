<?php

declare(strict_types=1);

use App\Http\Controllers\Agency\CalendarController;
use App\Http\Controllers\Agency\ClientController;
use App\Http\Controllers\Agency\DashboardController as AgencyDashboard;
use App\Http\Controllers\Agency\MediaController as AgencyMediaController;
use App\Http\Controllers\Agency\PostController as AgencyPostController;
use App\Http\Controllers\Agency\PostEditorController;
use App\Http\Controllers\Agency\TaskController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Install\InstallController;
use App\Http\Controllers\MaintenanceConsoleController;
use App\Http\Controllers\MediaController;
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
| Instalador web — hospedagem compartilhada sem SSH
|------------------------------------------------------------------------------
| Existe apenas enquanto o sistema não está instalado. Depois do primeiro
| usuário criado, estas rotas devolvem 404.
*/
Route::middleware('installer')->prefix('instalar')->name('install.')->group(function (): void {
    Route::get('/', [InstallController::class, 'requirements'])->name('requirements');

    Route::get('/ambiente', [InstallController::class, 'environmentForm'])->name('environment');
    Route::post('/ambiente', [InstallController::class, 'environmentStore'])->name('environment.store');

    Route::get('/banco', [InstallController::class, 'databaseForm'])->name('database');
    Route::post('/banco', [InstallController::class, 'databaseRun'])->name('database.run');

    Route::get('/administrador', [InstallController::class, 'administratorForm'])->name('administrator');
    Route::post('/administrador', [InstallController::class, 'administratorStore'])->name('administrator.store');
});

/*
|------------------------------------------------------------------------------
| Console de manutenção — o terminal que a hospedagem não oferece
|------------------------------------------------------------------------------
*/
Route::middleware('maintenance-console')->prefix('manutencao')->name('maintenance.')->group(function (): void {
    Route::get('/', [MaintenanceConsoleController::class, 'index'])->name('index');
    Route::post('/{comando}', [MaintenanceConsoleController::class, 'run'])
        ->middleware('throttle:20,1')
        ->name('run');
});

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

    Route::get('/calendario', CalendarController::class)->name('calendar');
    Route::get('/biblioteca', AgencyMediaController::class)->name('media');
    Route::get('/tarefas', TaskController::class)->name('tasks');

    Route::get('/posts/novo', [PostEditorController::class, 'create'])->name('posts.create');
    Route::get('/posts/{post}', [AgencyPostController::class, 'show'])->name('posts.show');
    Route::get('/posts/{post}/editar', [PostEditorController::class, 'edit'])->name('posts.edit');
});

/*
|------------------------------------------------------------------------------
| Mídia — entregue só depois da Policy (o arquivo vive fora do webroot)
|------------------------------------------------------------------------------
*/
Route::middleware('auth')->prefix('midia')->name('midia.')->group(function (): void {
    Route::get('/{asset}/miniatura', [MediaController::class, 'thumb'])->name('thumb');
    Route::get('/{asset}/preview', [MediaController::class, 'preview'])->name('preview');
    Route::get('/{asset}/original', [MediaController::class, 'original'])->name('original');
    Route::get('/logo/{client}', [MediaController::class, 'logo'])->name('logo');
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
