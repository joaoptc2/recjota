<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemHeartbeat;
use App\Support\MaintenanceCommands;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * Console de manutenção pelo navegador (Seção 11, hospedagem sem SSH).
 *
 * Substitui o terminal para as poucas operações que o dia a dia exige. Só roda
 * o que está em MaintenanceCommands — nenhuma entrada do usuário vira comando.
 */
class MaintenanceConsoleController extends Controller
{
    public function index(): View
    {
        $heartbeat = SystemHeartbeat::firstWhere('name', 'scheduler');

        return view('maintenance.console', [
            'comandos' => MaintenanceCommands::all(),
            'batimento' => $heartbeat,
            'cronParado' => $heartbeat === null || $heartbeat->isStale(),
            'saida' => session('saida'),
            'comandoExecutado' => session('comandoExecutado'),
        ]);
    }

    public function run(Request $request, string $comando): RedirectResponse
    {
        $definicao = MaintenanceCommands::find($comando);

        abort_if($definicao === null, 404);

        // Alguns comandos (fila, migrations) passam dos segundos de uma
        // requisição normal; aqui o limite é afrouxado de propósito (R5).
        @set_time_limit(120);

        try {
            $inicio = microtime(true);

            Artisan::call($definicao['comando'], $definicao['parametros']);

            $saida = trim(Artisan::output());
            $duracao = (int) ((microtime(true) - $inicio) * 1000);

            Log::info('Console de manutenção executou um comando.', [
                'comando' => $definicao['comando'],
                'usuario' => $request->user()?->email ?? 'token de emergência',
                'ip' => $request->ip(),
                'duracao_ms' => $duracao,
            ]);

            return redirect()->route('maintenance.index')
                ->with('comandoExecutado', $definicao['titulo'])
                ->with('saida', $saida !== '' ? $saida : 'Concluído em '.$duracao.' ms, sem saída.');
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('maintenance.index')
                ->with('comandoExecutado', $definicao['titulo'])
                ->with('saida', 'FALHOU: '.$e->getMessage());
        }
    }
}
