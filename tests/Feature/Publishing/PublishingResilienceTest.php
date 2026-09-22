<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Jobs\Publishing\CreateContainerJob;
use App\Jobs\Publishing\PublishContainerJob;
use App\Models\Client;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PublishLog;
use App\Models\SocialAccount;
use App\Services\Publishing\MediaContainerBuilder;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Casos de borda do motor que a revisão da Fase 4 apontou: retry sem
 * recriar container, instabilidade na consulta de status, carrossel grande
 * ou pequeno demais, conta de outro cliente, contador zerado a cada rodada
 * e ponte compartilhada entre posts.
 */
class PublishingResilienceTest extends TestCase
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

    public function test_falha_transitoria_no_media_publish_mantem_o_container_e_o_retry_nao_cria_outro(): void
    {
        Notification::fake();
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
            $this->urlContainer() => $this->fixtureResponse('container_status_finished'),
            $this->urlPublish() => Http::sequence()
                ->push(['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]], 500)
                ->push($this->fixture('media_publish')),
            $this->urlPermalink() => $this->fixtureResponse('permalink'),
            $this->urlComments() => $this->fixtureResponse('comment'),
        ]);
        $post = $this->postPronto();

        // 1ª rodada: container criado, FINISHED, media_publish cai com 5xx.
        $this->artisan('posts:dispatch-due')->assertSuccessful();

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id, 'O container pronto deveria ser mantido');
        $this->assertNotNull($post->container_created_at);
        $this->assertNull($post->locked_at);
        $this->assertNotNull($post->postMedia->first()->mediaAsset->fresh()->public_temp_path, 'A ponte deveria ficar de pé para o retry');

        // Retry: nada de /media nem de cota; só consulta o status e publica.
        $this->travelTo($post->next_attempt_at);
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('1 despachado(s)')->assertSuccessful();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(self::MEDIA_ID, $post->external_post_id);
        $this->assertSame(1, $this->chamadasPara('/media'));
        $this->assertSame(2, $this->chamadasPara('/media_publish'));
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'content_publishing_limit'))->count());
        $this->assertNull($post->postMedia->first()->mediaAsset->fresh()->public_temp_path);
    }

    public function test_container_mantido_mas_velho_demais_e_descartado_no_retry(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $post = $this->postPronto([
            'status' => PostStatus::Failed,
            'publish_attempts' => 1,
            'next_attempt_at' => now()->subMinute(),
            'external_container_id' => '404040',
            'container_created_at' => now()->subHours(25),
        ]);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id, 'Container de mais de 24h não vale mais: cria outro');
        $this->assertSame(1, $this->chamadasPara('/media'));
    }

    public function test_instabilidade_na_consulta_de_status_reconsulta_sem_gastar_tentativa(): void
    {
        Notification::fake();
        Bus::fake([PublishContainerJob::class, CheckContainerStatusJob::class]);
        Http::fake([$this->urlContainer() => Http::sequence()
            ->push(['error' => ['message' => 'An unknown error occurred', 'code' => 1]], 500)
            ->push($this->fixture('container_status_finished'))]);
        $post = $this->postPronto([
            'status' => PostStatus::Publishing,
            'external_container_id' => self::CONTAINER_ID,
            'container_created_at' => now()->subMinute(),
            'locked_at' => now(),
        ]);

        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(0, $post->publish_attempts);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
        $this->assertNotNull($post->container_next_check_at);
        Bus::assertDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->postId === $post->getKey() && $j->delay !== null);
        Bus::assertNotDispatched(PublishContainerJob::class);
        $this->assertFalse(PublishLog::query()->where('post_id', $post->getKey())->latest('id')->sole()->succeeded);

        // Segunda consulta: FINISHED, e a próxima checagem é zerada para o cron não reconsultar.
        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $post->refresh();
        $this->assertNull($post->container_next_check_at);
        Bus::assertDispatched(PublishContainerJob::class, fn (PublishContainerJob $j) => $j->postId === $post->getKey());
        Notification::assertNothingSent();
    }

    public function test_instabilidade_na_consulta_depois_do_prazo_conta_como_falha(): void
    {
        Bus::fake([PublishContainerJob::class, CheckContainerStatusJob::class]);
        Http::fake([$this->urlContainer() => Http::response(['error' => ['message' => 'x', 'code' => 1]], 500)]);
        $post = $this->postPronto([
            'status' => PostStatus::Publishing,
            'external_container_id' => self::CONTAINER_ID,
            'container_created_at' => now()->subMinutes(6),
            'locked_at' => now(),
        ]);

        (new CheckContainerStatusJob($post->getKey(), 1))->handle(...$this->deps(CheckContainerStatusJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertSame(1, $post->publish_attempts);
        Bus::assertNotDispatched(CheckContainerStatusJob::class);
    }

    public function test_carrossel_com_um_item_so_falha_de_vez_sem_chamar_a_api_de_midia(): void
    {
        Notification::fake();
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $post = $this->postPronto(midias: 1, tipo: PostType::Carousel);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertStringContainsString('de 2 a 10 itens; este tem 1', (string) $post->last_error);
        $this->assertSame(0, $this->chamadasPara('/media'));
    }

    public function test_carrossel_grande_cria_os_filhos_em_lotes_e_se_reenfileira(): void
    {
        Bus::fake([CheckContainerStatusJob::class, CreateContainerJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => Http::sequence()
                ->push(['id' => '1'])->push(['id' => '2'])->push(['id' => '3'])->push(['id' => '4'])
                ->push(['id' => '5'])->push(['id' => '6'])
                ->push($this->fixture('media_container')),
        ]);
        $post = $this->postPronto(midias: 6, tipo: PostType::Carousel);

        // 1ª execução: 4 filhos, lock liberado, job reenfileirado.
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertNull($post->external_container_id);
        $this->assertSame(['1', '2', '3', '4'], $post->publish_meta['children']);
        $this->assertFalse($post->isLocked());
        $this->assertSame(CreateContainerJob::CAROUSEL_CHILDREN_PER_RUN, $this->chamadasPara('/media'));
        Bus::assertDispatched(CreateContainerJob::class, fn (CreateContainerJob $j) => $j->postId === $post->getKey() && $j->version === 1);
        Bus::assertNotDispatched(CheckContainerStatusJob::class);

        // 2ª execução: os 2 que faltam e o pai, na ordem certa.
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
        $this->assertSame(['1', '2', '3', '4', '5', '6'], $post->publish_meta['children']);
        $this->assertSame(7, $this->chamadasPara('/media'));
        Http::assertSent(fn (Request $r) => ($r['media_type'] ?? null) === 'CAROUSEL' && $r['children'] === '1,2,3,4,5,6');
        Bus::assertDispatched(CheckContainerStatusJob::class, fn (CheckContainerStatusJob $j) => $j->postId === $post->getKey());
        // A cota vem do cache de 5 min: uma consulta serve às duas execuções.
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_contains($r->url(), 'content_publishing_limit'))->count());
    }

    public function test_conta_de_outro_cliente_falha_de_vez_sem_usar_o_token_dela(): void
    {
        Notification::fake();
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake();
        $outro = Client::factory()->configured()->create();
        $contaAlheia = SocialAccount::factory()->create(['client_id' => $outro->getKey(), 'access_token' => 'IGAAtokenDeOutroCliente000000000000000000']);
        $post = $this->postPronto(['social_account_id' => $contaAlheia->getKey()]);

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertStringContainsString('pertence a outro cliente', (string) $post->last_error);
        Http::assertNothingSent();
    }

    public function test_rodada_nova_zera_o_contador_e_o_erro_da_publicacao_anterior(): void
    {
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        // Falhou de vez numa rodada anterior; o usuário corrigiu e reagendou.
        $post = $this->postPronto([
            'status' => PostStatus::Scheduled,
            'publish_attempts' => Post::MAX_PUBLISH_ATTEMPTS + 1,
            'last_error' => 'Erro antigo',
            'last_error_is_permanent' => true,
        ]);

        $this->artisan('posts:dispatch-due')->expectsOutputToContain('1 despachado(s)')->assertSuccessful();
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps(CreateContainerJob::class));

        $post->refresh();
        $this->assertSame(PostStatus::Publishing, $post->status);
        $this->assertSame(0, $post->publish_attempts);
        $this->assertNull($post->last_error);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(self::CONTAINER_ID, $post->external_container_id);
    }

    public function test_liberar_a_ponte_preserva_midia_que_outro_post_em_publishing_ainda_usa(): void
    {
        $asset = $this->imagem('compartilhada.jpg');
        $a = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '1'], midias: 0);
        $b = $this->postPronto(['status' => PostStatus::Publishing, 'external_container_id' => '2'], midias: 0);
        PostMedia::create(['post_id' => $a->getKey(), 'media_asset_id' => $asset->getKey(), 'position' => 0]);
        PostMedia::create(['post_id' => $b->getKey(), 'media_asset_id' => $asset->getKey(), 'position' => 0]);

        $builder = app(MediaContainerBuilder::class);
        $builder->carouselItem($a->postMedia()->first());
        $this->assertNotNull($asset->fresh()->public_temp_path);

        $builder->releaseAll($a->fresh()->load('postMedia.mediaAsset'));
        $this->assertNotNull($asset->fresh()->public_temp_path, 'B ainda publica com esta mídia: a cópia pública fica');

        $b->transitionTo(PostStatus::Failed);
        $b->save();

        $builder->releaseAll($a->fresh()->load('postMedia.mediaAsset'));
        $this->assertNull(MediaAsset::query()->withoutGlobalScopes()->find($asset->getKey())->public_temp_path);
    }

    /** @return array<int, object> */
    private function deps(string $job): array
    {
        $params = (new \ReflectionMethod($job, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()->getName()), $params);
    }
}
