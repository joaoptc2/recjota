<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SystemHeartbeat;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SystemHeartbeat> */
class SystemHeartbeatFactory extends Factory
{
    protected $model = SystemHeartbeat::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'scheduler',
            'last_run_at' => now(),
            'last_duration_ms' => 12,
            'last_status' => 'ok',
            'last_message' => null,
        ];
    }

    /** Cron parado há mais tempo do que o limite de alerta (20 min). */
    public function stale(int $minutes = 45): static
    {
        return $this->state(fn () => ['last_run_at' => now()->subMinutes($minutes)]);
    }
}
