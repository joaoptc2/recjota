<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Jobs\Publishing\CreateContainerJob;
use App\Jobs\Publishing\PublishContainerJob;
use App\Models\Post;
use App\Support\Enums\ApprovalStatus;
use App\Support\Enums\PostStatus;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/** posts:dispatch-due e instagram:check-containers (Seção 8) e seu agendamento. */
class PublishingCommandsTest extends TestCase
{
    use PublishingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublishing();
    }

    protected function tearDown(): void
    {
        $this->tearDownPublishing();

        parent::tearDown();
    }

    public function test_despacha_so_o_que_esta_na_hora_e_elegivel(): void
    {
        Bus::fake();

        $agendado = $this->postPronto();
        $aprovado = $this->postPronto(['status' => PostStatus::Approved]);
        $semAprovacao = $this->postPronto(['approval_status' => ApprovalStatus::NotRequired, 'approved_version' => null]);
        $retry = $this->postPronto(['status' => PostStatus::Failed, 'publish_attempts' => 2, 'next_attempt_at' => now()->subSecond(), 'last_error_is_permanent' => false]);

        $futuro = $this->postPronto(['scheduled_at' => now()->addHour()]);
        $rascunho = $this->postPronto(['status' => PostStatus::Draft]);
        $aguardando = $this->postPronto(['status' => PostStatus::AwaitingClient]);
        $publicando = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => self::CONTAINER_ID]);
        $retryCedo = $this->postPronto(['status' => PostStatus::Failed, 'publish_attempts' => 1, 'next_attempt_at' => now()->addMinutes(4)]);
        $permanente = $this->postPronto(['status' => PostStatus::Failed, 'last_error_is_permanent' => true, 'next_attempt_at' => now()->subMinute()]);
        $esgotado = $this->postPronto(['status' => PostStatus::Failed, 'publish_attempts' => Post::MAX_PUBLISH_ATTEMPTS + 1, 'next_attempt_at' => now()->subMinute()]);
        $travado = $this->postPronto(['locked_at' => now()->subMinutes(2)]);
        // Retry cujo post foi reagendado para o futuro: espera a nova hora.
        $retryReagendado = $this->postPronto(['status' => PostStatus::Failed, 'publish_attempts' => 1, 'next_attempt_at' => now()->subMinute(), 'scheduled_at' => now()->addHour()]);
        // Job morreu entre o lock e o container: com o lock vencido, retoma.
        $presoComLockVivo = $this->postPronto(['status' => PostStatus::Publishing, 'locked_at' => now()->subMinutes(2)]);
        // Aprovado na v1, editado depois (v2): não sai (Seção 6.6).
        $editadoDepois = $this->postPronto(['current_version' => 2, 'approved_version' => 1]);

        // Sem conta: sai mesmo assim, para falhar com instrução clara em vez de ficar agendado para sempre.
        $semConta = $this->postPronto(['social_account_id' => null]);
        $presoComLockVencido = $this->postPronto(['status' => PostStatus::Publishing, 'locked_at' => now()->subMinutes(20)]);

        $this->artisan('posts:dispatch-due')
            ->expectsOutputToContain('6 despachado(s), 1 pulado(s)')
            ->assertSuccessful();

        foreach ([$agendado, $aprovado, $semAprovacao, $retry, $semConta, $presoComLockVencido] as $post) {
            Bus::assertDispatched(CreateContainerJob::class, fn (CreateContainerJob $j) => $j->postId === $post->getKey() && $j->version === $post->current_version);
        }

        foreach ([$futuro, $rascunho, $aguardando, $publicando, $retryCedo, $permanente, $esgotado, $travado, $retryReagendado, $presoComLockVivo, $editadoDepois] as $post) {
            Bus::assertNotDispatched(CreateContainerJob::class, fn (CreateContainerJob $j) => $j->postId === $post->getKey());
        }
    }

    public function test_limite_de_vinte_por_execucao(): void
    {
        Bus::fake();

        Post::factory()->count(25)->create([
            'client_id' => $this->cliente->getKey(),
            'social_account_id' => $this->conta->getKey(),
            'status' => PostStatus::Scheduled,
            'approval_status' => ApprovalStatus::NotRequired,
            'scheduled_at' => now()->subMinutes(5),
        ]);

        $this->artisan('posts:dispatch-due')->expectsOutputToContain('20 despachado(s)')->assertSuccessful();
        Bus::assertDispatchedTimes(CreateContainerJob::class, 20);

        $this->artisan('posts:dispatch-due', ['--limite' => 3])->expectsOutputToContain('3 despachado(s)')->assertSuccessful();
    }

    public function test_check_containers_reenfileira_so_as_checagens_vencidas(): void
    {
        Bus::fake();

        $vencida = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => self::CONTAINER_ID, 'container_created_at' => now()->subMinutes(3), 'container_next_check_at' => now()->subMinutes(2)]);
        $semPrevisao = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '999', 'container_created_at' => now()->subMinutes(3), 'container_next_check_at' => null]);
        $noPrazo = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '888', 'container_next_check_at' => now()->addSeconds(30)]);
        $recemVencida = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '777', 'container_next_check_at' => now()->subSeconds(20)]);
        $jaPublicou = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '666', 'external_post_id' => self::MEDIA_ID, 'container_next_check_at' => now()->subMinutes(5)]);
        $semContainer = $this->postPronto(['status' => PostStatus::Publishing]);

        $this->artisan('instagram:check-containers')->expectsOutputToContain('2 checagem(ns)')->assertSuccessful();

        Bus::assertDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->postId === $vencida->getKey());
        Bus::assertDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->postId === $semPrevisao->getKey());
        Bus::assertDispatchedTimes(CheckContainerStatusJob::class, 2);

        foreach ([$noPrazo, $recemVencida, $jaPublicou, $semContainer] as $post) {
            Bus::assertNotDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->postId === $post->getKey());
        }

        // A previsão avança para não reenfileirar de novo no minuto seguinte.
        $this->assertTrue($vencida->fresh()->container_next_check_at->greaterThan(now()));
        $this->artisan('instagram:check-containers')->expectsOutputToContain('0 checagem(ns)')->assertSuccessful();
    }

    public function test_agendamentos_por_minuto_estao_registrados_sem_schedule_command(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        foreach (['despachar-publicacoes', 'checar-containers'] as $nome) {
            $evento = $eventos->first(fn ($e) => $e->description === $nome);

            $this->assertNotNull($evento, "Agendamento {$nome} ausente em routes/console.php");
            $this->assertSame('* * * * *', $evento->expression);
            $this->assertInstanceOf(CallbackEvent::class, $evento);
            $this->assertTrue($evento->withoutOverlapping);
            $this->assertSame(2, $evento->expiresAt);
        }
    }

    public function test_jobs_rodam_com_uma_unica_tentativa_da_fila(): void
    {
        foreach ([CreateContainerJob::class, CheckContainerStatusJob::class, PublishContainerJob::class] as $job) {
            $instancia = new $job(1, 1);

            $this->assertInstanceOf(ShouldQueue::class, $instancia);
            $this->assertSame(1, $instancia->tries, "{$job} deveria ter tries=1: o retry é regra de negócio");
        }
    }
}
