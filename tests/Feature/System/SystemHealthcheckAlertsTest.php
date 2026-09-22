<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Models\SystemHeartbeat;
use App\Models\User;
use App\Notifications\SystemAlert;
use App\Support\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Alertas do batimento (Seção 11.2): cron parado, jobs falhados e tokens
 * vencendo chegam ao owner por e-mail, uma vez a cada 12h por tipo.
 */
class SystemHealthcheckAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();

        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->admin = $this->userWithRole(RoleName::Admin);
    }

    public function test_batimento_anterior_parado_avisa_o_owner(): void
    {
        SystemHeartbeat::factory()->stale(45)->create();

        $this->artisan('system:healthcheck')
            ->expectsOutputToContain('Agendador havia parado')
            ->assertSuccessful();

        Notification::assertSentTo($this->owner, SystemAlert::class, function (SystemAlert $n, array $canais): bool {
            return $n->tipo === SystemAlert::TIPO_CRON_PARADO
                && in_array('mail', $canais, true)
                && in_array('database', $canais, true)
                && str_contains($n->url, '/painel/configuracoes');
        });
        Notification::assertNotSentTo($this->admin, SystemAlert::class);

        $this->assertFalse(SystemHeartbeat::firstWhere('name', 'scheduler')->isStale());
    }

    public function test_batimento_recente_ou_inexistente_nao_avisa(): void
    {
        $this->artisan('system:healthcheck')->assertSuccessful();

        SystemHeartbeat::query()->update(['last_run_at' => now()->subMinutes(5)]);
        $this->artisan('system:healthcheck')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_alerta_de_cron_parado_nao_repete_dentro_de_12h(): void
    {
        SystemHeartbeat::factory()->stale(45)->create();
        $this->artisan('system:healthcheck')->assertSuccessful();

        SystemHeartbeat::query()->update(['last_run_at' => now()->subMinutes(30)]);
        $this->artisan('system:healthcheck')
            ->expectsOutputToContain('já enviado nas últimas 12h')
            ->assertSuccessful();

        Notification::assertSentToTimes($this->owner, SystemAlert::class, 1);

        $this->travel(13)->hours();
        SystemHeartbeat::query()->update(['last_run_at' => now()->subMinutes(30)]);
        $this->artisan('system:healthcheck')->assertSuccessful();

        Notification::assertSentToTimes($this->owner, SystemAlert::class, 2);
    }

    public function test_jobs_falhados_desde_o_ultimo_batimento_geram_alerta(): void
    {
        SystemHeartbeat::factory()->create(['last_run_at' => now()->subMinutes(10)]);

        $this->insertFailedJob(now()->subMinutes(2));

        $this->artisan('system:healthcheck')->assertSuccessful();

        Notification::assertSentTo($this->owner, SystemAlert::class, fn (SystemAlert $n) => $n->tipo === SystemAlert::TIPO_JOBS_FALHADOS
            && str_contains($n->titulo, '1 job(s)'));
    }

    public function test_jobs_falhados_antigos_nao_geram_alerta_novo(): void
    {
        SystemHeartbeat::factory()->create(['last_run_at' => now()->subMinutes(10)]);

        $this->insertFailedJob(now()->subHours(3));

        $this->artisan('system:healthcheck')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_token_vencendo_em_menos_de_3_dias_gera_alerta_com_a_conta(): void
    {
        $cliente = Client::factory()->configured()->create();
        SystemHeartbeat::factory()->create();

        $quaseVencido = SocialAccount::factory()->create(['client_id' => $cliente->getKey(), 'token_expires_at' => now()->addDays(2)]);
        SocialAccount::factory()->create(['client_id' => $cliente->getKey(), 'token_expires_at' => now()->addDays(5)]);
        SocialAccount::factory()->expired()->create(['client_id' => $cliente->getKey()]);

        $this->artisan('system:healthcheck')->assertSuccessful();

        Notification::assertSentTo($this->owner, SystemAlert::class, fn (SystemAlert $n) => $n->tipo === SystemAlert::TIPO_TOKENS_VENCENDO
            && str_contains($n->titulo, '1 conta(s)')
            && str_contains($n->mensagem, $quaseVencido->handle())
            && str_contains($n->mensagem, $cliente->name));
    }

    public function test_o_email_de_alerta_renderiza_com_link_para_configuracoes(): void
    {
        $html = (new SystemAlert(SystemAlert::TIPO_CRON_PARADO, 'O cron parou', 'Ficou 40 minutos sem bater.', 'Confira o cron no hPanel.', route('painel.settings')))
            ->toMail($this->owner)
            ->render();

        $this->assertStringContainsString('O cron parou', (string) $html);
        $this->assertStringContainsString('Confira o cron no hPanel.', (string) $html);
        $this->assertStringContainsString(route('painel.settings'), (string) $html);
    }

    private function insertFailedJob(\DateTimeInterface $quando): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['uuid' => (string) Str::uuid(), 'displayName' => 'App\\Jobs\\Publishing\\CreateContainerJob', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 1, 'data' => ['commandName' => 'App\\Jobs\\Publishing\\CreateContainerJob']]),
            'exception' => "RuntimeException: Boom\n#0 ...",
            'failed_at' => $quando,
        ]);
    }
}
