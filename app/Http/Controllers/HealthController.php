<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SystemHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Endpoint público de saúde (Seção 11.2). Confirma banco, storage gravável e
 * último batimento do cron. Não expõe nenhum dado de cliente.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->check(fn () => DB::connection()->getPdo() !== null),
            'storage_writable' => $this->check(function (): bool {
                Storage::disk('local')->put('health.txt', (string) now());

                return Storage::disk('local')->exists('health.txt');
            }),
            'cron_heartbeat' => $this->cronHeartbeat(),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    /** @return array{ok: bool, detail: string} */
    private function check(callable $probe): array
    {
        try {
            return ['ok' => (bool) $probe(), 'detail' => 'ok'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => class_basename($e)];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function cronHeartbeat(): array
    {
        try {
            $heartbeat = SystemHeartbeat::where('name', 'scheduler')->first();
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => class_basename($e)];
        }

        if ($heartbeat === null) {
            return ['ok' => false, 'detail' => 'nunca executado'];
        }

        return [
            'ok' => ! $heartbeat->isStale(),
            'detail' => $heartbeat->last_run_at?->toIso8601String() ?? 'nunca executado',
        ];
    }
}
