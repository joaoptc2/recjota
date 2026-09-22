<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Jobs\Publishing\CreateContainerJob;
use App\Jobs\Publishing\PublishContainerJob;
use App\Models\PublishLog;
use App\Notifications\PostPublished;
use App\Notifications\PostPublishFailed;
use App\Services\Media\PublicMediaBridge;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Enums\PublishStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Motor de publicação sem worker (Seções 6.7, 7.1.4, 8): jobs encadeados,
 * lock, idempotência, ponte de mídia e registro de cada etapa.
 */
class PublishingEngineTest extends TestCase
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

    public function test_caminho_feliz_de_imagem_encadeia_as_tres_etapas(): void
    {
        Notification::fake();
        $this->fakeHappyPublishing();
        $post = $this->postPronto();

        // Etapa 0: o despachante enfileira.
        Bus::fake([CreateContainerJob::class]);
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('1 despachado(s)')->assertSuccessful();
        Bus::assertDispatched(CreateContainerJob::class, fn (CreateContainerJob $j) => $j->postId === $post->getKey() && $j->version === 1);

        // Etapa 1: container criado, checagem agendada para +60s.
        Bus::fake([CheckContainerStatusJob::class]);
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
        $this->assertNotNull($post->container_created_at);
        $this->assertTrue($post->isLocked());
        $this->assertNotNull($post->postMedia->first()->mediaAsset->public_temp_path, 'A mídia deveria estar na ponte pública');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media')
            && str_starts_with((string) $r['image_url'], 'https://agencia.example.com/media-tmp/')
            && $r['caption'] === 'Legenda de teste #recjota'
            && ! isset($r['media_type']));

        Bus::assertDispatched(CheckContainerStatusJob::class, function (CheckContainerStatusJob $j) use ($post): bool {
            return $j->postId === $post->getKey()
                && $j->version === 1
                && $j->delay !== null
                && (int) round(now()->diffInSeconds($j->delay, absolute: true)) === CreateContainerJob::CHECK_DELAY_SECONDS;
        });

        // Etapa 2: FINISHED dispara a publicação.
        Bus::fake([PublishContainerJob::class]);
        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));
        Bus::assertDispatched(PublishContainerJob::class, fn (PublishContainerJob $j) => $j->postId === $post->getKey());

        // Etapa 3: publica, comenta, libera ponte e lock, avisa.
        (new PublishContainerJob($post->getKey(), 1))->handle(...$this->deps(PublishContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(self::MEDIA_ID, $post->external_post_id);
        $this->assertSame('https://www.instagram.com/p/CxYz123AbCd/', $post->external_permalink);
        $this->assertNotNull($post->published_at);
        $this->assertNull($post->locked_at);
        $this->assertNull($post->last_error);
        $this->assertSame('17870913679156914', $post->publish_meta['comment_id']);

        $asset = $post->postMedia->first()->mediaAsset->fresh();
        $this->assertNull($asset->public_temp_path, 'A ponte deveria ter sido liberada');
        $this->assertDirectoryDoesNotExist($this->ponte.'/'.$asset->ulid);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media_publish') && $r['creation_id'] === self::CONTAINER_ID);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/comments') && $r['message'] === '#hashtags #no #comentario');

        Notification::assertSentTo($this->autor, PostPublished::class);
        Notification::assertSentTo($this->gestor, PostPublished::class, fn (PostPublished $n, array $canais) => $n->post->is($post)
            && in_array('mail', $canais, true) && in_array('database', $canais, true));

        $etapas = PublishLog::query()->where('post_id', $post->getKey())->orderBy('id')->pluck('stage')->map(fn ($s) => $s->value)->all();
        $this->assertSame(['quota', 'container', 'status', 'publish', 'publish', 'comment'], $etapas);
        $this->assertTrue(PublishLog::query()->where('post_id', $post->getKey())->get()->every(fn (PublishLog $l) => $l->succeeded));
    }

    public function test_fluxo_completo_com_fila_sincrona_publica_de_ponta_a_ponta(): void
    {
        Notification::fake();
        $this->fakeHappyPublishing();
        $post = $this->postPronto();

        $this->artisan('posts:dispatch-due')->assertSuccessful();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(self::MEDIA_ID, $post->external_post_id);
        $this->assertSame(1, $this->chamadasPara('/media'));
        $this->assertSame(1, $this->chamadasPara('/media_publish'));
    }

    public function test_carrossel_de_tres_itens_cria_tres_filhos_e_o_pai(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => Http::sequence()
                ->push(['id' => '111'])
                ->push(['id' => '222'])
                ->push(['id' => '333'])
                ->push($this->fixture('media_container')),
        ]);
        $post = $this->postPronto(midias: 3, tipo: PostType::Carousel);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
        $this->assertSame(['111', '222', '333'], $post->publish_meta['children']);
        $this->assertSame(4, $this->chamadasPara('/media'));

        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/content_publishing_limit') || str_contains($r->url(), 'content_publishing_limit'),
            fn (Request $r) => (bool) $r['is_carousel_item'] === true && isset($r['image_url']) && ! isset($r['caption']),
            fn (Request $r) => (bool) $r['is_carousel_item'] === true,
            fn (Request $r) => (bool) $r['is_carousel_item'] === true,
            fn (Request $r) => $r['media_type'] === 'CAROUSEL' && $r['children'] === '111,222,333' && $r['caption'] === 'Legenda de teste #recjota',
        ]);

        // Cada item foi para a ponte com uma URL própria.
        $urls = Http::recorded(fn (Request $r) => isset($r['image_url']))->map(fn (array $par) => $par[0]['image_url'])->unique();
        $this->assertCount(3, $urls);
    }

    public function test_reexecutar_create_container_nao_cria_segundo_container(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $this->assertSame(1, $this->chamadasPara('/media'));
        $this->assertSame(self::CONTAINER_ID, $post->fresh()->external_container_id);
        $this->assertSame(1, PublishLog::query()->where('post_id', $post->getKey())->where('stage', PublishStage::Container->value)->count());
        Bus::assertDispatchedTimes(CheckContainerStatusJob::class, 1);
    }

    public function test_publish_container_reexecutado_nao_publica_duas_vezes(): void
    {
        Notification::fake();
        $this->fakeHappyPublishing();
        $post = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => self::CONTAINER_ID, 'locked_at' => now()]);

        (new PublishContainerJob($post->getKey(), 1))->handle(...$this->deps(PublishContainerJob::class));
        (new PublishContainerJob($post->getKey(), 1))->handle(...$this->deps(PublishContainerJob::class));

        $this->assertSame(1, $this->chamadasPara('/media_publish'));
        $this->assertSame(1, $this->chamadasPara('/comments'));
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_post_com_lock_recente_nao_e_processado(): void
    {
        Http::fake();
        $post = $this->postPronto(['locked_at' => now()->subMinutes(3)]);

        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        Http::assertNothingSent();
        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
    }

    public function test_lock_vencido_e_tomado_pelo_proximo_job(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $post = $this->postPronto(['locked_at' => now()->subMinutes(16)]);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
        $this->assertSame(1, $this->chamadasPara('/media'));
    }

    public function test_post_retomado_em_publishing_sem_container_e_com_lock_vencido_continua(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        // Job anterior morreu depois de entrar em publishing e antes do container.
        $post = $this->postPronto(['status' => PostStatus::Publishing, 'locked_at' => now()->subMinutes(20)]);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
        $this->assertTrue($post->isLocked());
    }

    public function test_container_error_falha_de_vez_sem_retry_e_libera_a_ponte(): void
    {
        Notification::fake();
        Bus::fake([PublishContainerJob::class, CheckContainerStatusJob::class]);
        Http::fake([$this->urlContainer() => $this->fixtureResponse('container_status_error')]);

        $post = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => self::CONTAINER_ID, 'container_created_at' => now()->subMinute(), 'locked_at' => now()]);
        $asset = $post->postMedia->first()->mediaAsset;
        app(PublicMediaBridge::class)->publish($asset);
        $this->assertNotNull($asset->fresh()->public_temp_path);

        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertNull($post->next_attempt_at);
        $this->assertNull($post->external_container_id);
        $this->assertNull($post->locked_at);
        $this->assertStringContainsString('recusou a mídia', (string) $post->last_error);
        $this->assertStringContainsString('too long', (string) $post->last_error);
        $this->assertNull($asset->fresh()->public_temp_path);

        Bus::assertNotDispatched(PublishContainerJob::class);
        Bus::assertNotDispatched(CheckContainerStatusJob::class);
        Notification::assertSentTo($this->gestor, PostPublishFailed::class, fn (PostPublishFailed $n) => $n->stage === PublishStage::Status);

        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();
    }

    public function test_in_progress_reagenda_a_checagem_e_estoura_apos_cinco_minutos(): void
    {
        Bus::fake([CheckContainerStatusJob::class, PublishContainerJob::class]);
        Http::fake([$this->urlContainer() => $this->fixtureResponse('container_status_in_progress')]);

        $post = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => self::CONTAINER_ID, 'container_created_at' => now()->subMinutes(2), 'locked_at' => now()]);

        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $this->assertSame(PostStatus::Publishing, $post->fresh()->status);
        $this->assertNotNull($post->fresh()->container_next_check_at);
        Bus::assertDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->delay !== null);
        Bus::assertNotDispatched(PublishContainerJob::class);

        // Passaram-se mais de 5 minutos desde a criação: falha transitória.
        $post->forceFill(['container_created_at' => now()->subMinutes(6)])->save();
        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertNotNull($post->next_attempt_at);
    }

    public function test_publish_logs_registram_cada_etapa_com_token_mascarado(): void
    {
        Notification::fake();
        $this->fakeHappyPublishing();
        $post = $this->postPronto();

        $this->artisan('posts:dispatch-due')->assertSuccessful();

        $logs = DB::table('publish_logs')->where('post_id', $post->getKey())->get();
        $this->assertGreaterThanOrEqual(5, $logs->count());

        foreach ($logs as $log) {
            $this->assertStringNotContainsString(self::TOKEN, (string) $log->request, 'Token inteiro vazou no request de '.$log->stage);
            $this->assertStringNotContainsString(self::TOKEN, (string) $log->response);
            $this->assertStringNotContainsString(self::TOKEN, (string) $log->error);
        }

        $container = PublishLog::query()->where('post_id', $post->getKey())->where('stage', PublishStage::Container->value)->firstOrFail();
        $this->assertSame('IGAA…0000', $container->request['access_token']);
        $this->assertSame(self::CONTAINER_ID, $container->response['id']);
        $this->assertSame(1, $container->attempt);
        $this->assertTrue($container->succeeded);
    }

    public function test_publishing_so_sai_para_published_ou_failed(): void
    {
        $this->assertSame(
            [PostStatus::Published, PostStatus::Failed],
            PostStatus::Publishing->allowedTransitions(),
        );

        // Reagendamento por cota acontece ANTES de entrar em publishing: de
        // scheduled/approved/failed dá para ir a scheduled; de publishing, não.
        $this->assertTrue(PostStatus::Approved->canTransitionTo(PostStatus::Scheduled));
        $this->assertTrue(PostStatus::Failed->canTransitionTo(PostStatus::Scheduled));
        $this->assertTrue(PostStatus::Failed->canTransitionTo(PostStatus::Publishing));
        $this->assertFalse(PostStatus::Publishing->canTransitionTo(PostStatus::Scheduled));
    }

    public function test_versao_diferente_da_despachada_nao_roda(): void
    {
        Http::fake();
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 99))->handle(...$this->deps(CreateContainerJob::class));

        Http::assertNothingSent();
        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
    }

    /**
     * Resolve as dependências do handle() pelo container, na ordem declarada.
     *
     * @return array<int, object>
     */
    protected function deps(string $job): array
    {
        $params = (new \ReflectionMethod($job, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()->getName()), $params);
    }
}
