<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_batimento_do_cron_o_sistema_se_declara_degradado(): void
    {
        $this->get(route('health'))
            ->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.cron_heartbeat.ok', false);
    }

    public function test_com_batimento_recente_o_sistema_se_declara_saudavel(): void
    {
        SystemHeartbeat::create(['name' => 'scheduler', 'last_run_at' => now()]);

        $this->get(route('health'))
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    public function test_batimento_antigo_derruba_o_healthcheck(): void
    {
        SystemHeartbeat::create(['name' => 'scheduler', 'last_run_at' => now()->subHour()]);

        $this->get(route('health'))->assertStatus(503);
    }
}
