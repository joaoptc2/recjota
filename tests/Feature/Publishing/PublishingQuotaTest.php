<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Jobs\Publishing\CreateContainerJob;
use App\Models\PublishingQuota;
use App\Models\PublishLog;
use App\Notifications\PostRescheduledByQuota;
use App\Services\Publishing\PublishingQuotaGuard;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PublishStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cota de 50 publicações por 24h (Seção 7.1.5): reagendar em vez de tentar e falhar. */
class PublishingQuotaTest extends TestCase
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

    public function test_cota_esgotada_reagenda_para_a_proxima_janela_sem_chamar_media(): void
    {
        Notification::fake();
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => Http::response(['data' => [['quota_usage' => 50, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]]),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $post = $this->postPronto(['status' => PostStatus::Approved]);
        $agendadoAntes = $post->scheduled_at->copy();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(0, $this->chamadasPara('/media'));
        $this->assertSame(PostStatus::Scheduled, $post->status);
        $this->assertNull($post->locked_at);
        $this->assertNull($post->external_container_id);
        $this->assertStringContainsString('limite de 50 publicações', (string) $post->last_error);
        $this->assertFalse($post->last_error_is_permanent);

        $janela = PublishingQuota::query()->where('social_account_id', $this->conta->getKey())->firstOrFail();
        $this->assertSame(50, $janela->used_count);
        $this->assertSame(50, $janela->quota_total);
        $this->assertTrue($janela->isExhausted());
        $this->assertTrue($post->scheduled_at->equalTo($janela->window_start->copy()->addDay()->addMinute()));
        $this->assertTrue($post->scheduled_at->greaterThan($agendadoAntes));

        Notification::assertSentTo($this->gestor, PostRescheduledByQuota::class, fn (PostRescheduledByQuota $n) => $n->post->is($post) && $n->quotaTotal === 50);
        Notification::assertNotSentTo($this->autor, PostRescheduledByQuota::class);
        Bus::assertNotDispatched(CheckContainerStatusJob::class);

        $log = PublishLog::query()->where('post_id', $post->getKey())->where('stage', PublishStage::Quota->value)->firstOrFail();
        $this->assertFalse($log->succeeded);
        $this->assertSame(50, $log->response['quota_usage']);

        // Não está mais na hora: o despachante não pega de novo.
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();
    }

    public function test_post_retomado_em_publishing_com_cota_esgotada_vira_failed_com_proxima_tentativa_na_janela(): void
    {
        Notification::fake();
        Http::fake([
            $this->urlQuota() => Http::response(['data' => [['quota_usage' => 50, 'config' => ['quota_total' => 50, 'quota_duration' => 86400]]]]),
        ]);
        $post = $this->postPronto(['status' => PostStatus::Publishing, 'locked_at' => now()->subMinutes(20)]);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(0, $post->publish_attempts, 'Nada foi tentado: não conta tentativa');
        $this->assertTrue($post->next_attempt_at->equalTo($post->scheduled_at));
        $this->assertTrue($post->next_attempt_at->greaterThan(now()->addHours(23)));
        Notification::assertSentTo($this->gestor, PostRescheduledByQuota::class);
    }

    public function test_leitura_da_cota_fica_em_cache_por_cinco_minutos(): void
    {
        Http::fake([$this->urlQuota() => $this->fixtureResponse('content_publishing_limit')]);
        $guard = app(PublishingQuotaGuard::class);

        $guard->check($this->conta);
        $guard->check($this->conta);

        Http::assertSentCount(1);
        $this->assertTrue(Cache::has('publishing-limit:'.$this->conta->getKey()));

        $this->travel(6)->minutes();
        $guard->check($this->conta);
        Http::assertSentCount(2);

        $janela = PublishingQuota::query()->where('social_account_id', $this->conta->getKey())->get();
        $this->assertCount(1, $janela, 'Uma janela por 24h, atualizada a cada consulta');
        $this->assertSame(3, $janela->first()->used_count);
    }

    public function test_publicar_invalida_o_cache_e_sobe_o_espelho_local(): void
    {
        Http::fake([$this->urlQuota() => $this->fixtureResponse('content_publishing_limit')]);
        $guard = app(PublishingQuotaGuard::class);

        $guard->check($this->conta);
        $guard->consume($this->conta);

        $this->assertFalse(Cache::has('publishing-limit:'.$this->conta->getKey()));
        $this->assertSame(4, PublishingQuota::query()->where('social_account_id', $this->conta->getKey())->firstOrFail()->used_count);
    }

    public function test_erro_da_api_na_consulta_de_cota_passa_pelo_tratador_de_falhas(): void
    {
        Notification::fake();
        Http::fake([$this->urlQuota() => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 503)]);
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertNull($post->locked_at);
    }

    /** @return array<int, object> */
    private function deps(): array
    {
        $params = (new \ReflectionMethod(CreateContainerJob::class, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()->getName()), $params);
    }
}
