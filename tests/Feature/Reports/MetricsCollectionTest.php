<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\Metrics\SyncAccountMetricsJob;
use App\Jobs\Metrics\SyncPostMetricsJob;
use App\Models\Client;
use App\Models\MetricAccountDaily;
use App\Models\MetricPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Integrations\Instagram\InstagramInsights;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Integrations\InstagramFixtures;
use Tests\TestCase;

/**
 * Coleta diária de métricas (Seção 6.8): o que a API não informa fica null,
 * o catálogo de métricas mutável da Meta é tolerado, e a coleta é
 * idempotente por dia.
 */
class MetricsCollectionTest extends TestCase
{
    use InstagramFixtures;
    use RefreshDatabase;

    private const IG_USER_ID = '17841405793187218';

    private const MEDIA_ID = '17895695668004550';

    private Client $cliente;

    private SocialAccount $conta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        $this->configureInstagram();

        $this->cliente = Client::factory()->configured()->create(['timezone' => 'America/Sao_Paulo']);
        $this->conta = SocialAccount::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'external_id' => self::IG_USER_ID,
            'access_token' => 'IGAAtokenDaContaParaMetricas000000000000000',
        ]);
    }

    private function urlUser(string $sufixo = ''): string
    {
        return 'graph.instagram.com/v23.0/'.self::IG_USER_ID.$sufixo;
    }

    public function test_insights_da_conta_usam_serie_e_total_value_e_toleram_metrica_recusada(): void
    {
        Http::fake([
            $this->urlUser('?fields=*') => $this->fixtureResponse('account_fields'),
            $this->urlUser('/insights?metric=reach%2Cimpressions*') => $this->fixtureResponse('error_100_metric', 400),
            $this->urlUser('/insights?metric=reach&*') => $this->fixtureResponse('account_insights_series'),
            $this->urlUser('/insights?metric=profile_views%2Cwebsite_clicks*') => $this->fixtureResponse('account_insights_total_value'),
        ]);

        $insights = app(InstagramInsights::class);
        $snapshot = $insights->accountSnapshot($this->conta);
        $dia = $insights->accountDailyInsights($this->conta, now()->subDay());

        $this->assertSame(4321, $snapshot->followers);
        $this->assertSame(210, $snapshot->follows);
        $this->assertSame(1530, $dia->reach);
        $this->assertNull($dia->impressions, 'impressions recusada pela API vira indisponível, não zero');
        $this->assertSame(87, $dia->profileViews);
        $this->assertSame(12, $dia->websiteClicks);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'metric_type=total_value') && str_contains($r->url(), 'period=day'));
    }

    public function test_insights_de_midia_mapeiam_views_conforme_o_tipo_e_calculam_engajamento(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'?fields=*' => $this->fixtureResponse('media_fields'),
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/insights?metric=reach%2Csaved%2Cshares%2Cviews%2Cimpressions*' => $this->fixtureResponse('error_100_metric', 400),
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/insights?metric=reach%2Csaved%2Cshares%2Cviews&*' => $this->fixtureResponse('media_insights_feed'),
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/insights?metric=reach%2Csaved%2Cshares%2Cviews%2Cplays*' => $this->fixtureResponse('media_insights_reels'),
        ]);
        $insights = app(InstagramInsights::class);

        $imagem = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'type' => PostType::FeedImage, 'external_post_id' => self::MEDIA_ID]);
        $feed = $insights->mediaInsights($this->conta, $imagem);

        $this->assertSame(3200, $feed->reach);
        $this->assertSame(240, $feed->likes);
        $this->assertSame(18, $feed->comments);
        $this->assertSame(45, $feed->saves);
        $this->assertSame(9, $feed->shares);
        $this->assertSame(4100, $feed->impressions, 'views de imagem entra como impressões');
        $this->assertNull($feed->videoViews);
        $this->assertSame(round((240 + 18 + 45 + 9) / 3200 * 100, 4), $feed->engagementRate());

        $reel = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'type' => PostType::Reel, 'external_post_id' => self::MEDIA_ID]);
        $reels = $insights->mediaInsights($this->conta, $reel);

        $this->assertSame(9800, $reels->reach);
        $this->assertSame(15400, $reels->videoViews, 'views de reel entra como visualizações de vídeo');
        $this->assertNull($reels->impressions);
    }

    public function test_sem_alcance_o_engajamento_e_indisponivel(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'?fields=*' => $this->fixtureResponse('media_fields'),
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/insights*' => $this->fixtureResponse('error_100_metric', 400),
        ]);
        $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => self::MEDIA_ID]);

        $dados = app(InstagramInsights::class)->mediaInsights($this->conta, $post);

        $this->assertNull($dados->reach);
        $this->assertSame(240, $dados->likes);
        $this->assertNull($dados->engagementRate());
        $this->assertArrayHasKey('indisponivel', $dados->raw['insights']);
    }

    public function test_comandos_enfileiram_so_contas_conectadas_e_posts_publicados_recentes(): void
    {
        Bus::fake();

        $expirada = SocialAccount::factory()->expired()->create(['client_id' => $this->cliente->getKey()]);
        $recente = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => '1', 'published_at' => now()->subDays(3)]);
        $antigo = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => '2', 'published_at' => now()->subDays(45)]);
        $semMedia = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => null]);
        $contaExpirada = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $expirada->getKey(), 'external_post_id' => '3']);
        $agendado = Post::factory()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'status' => PostStatus::Scheduled, 'external_post_id' => null]);

        $this->artisan('metrics:sync-accounts')
            ->expectsOutputToContain('1 conta(s) enfileirada(s) para '.now()->subDay()->toDateString())
            ->assertSuccessful();

        Bus::assertDispatched(SyncAccountMetricsJob::class, fn (SyncAccountMetricsJob $j) => $j->socialAccountId === $this->conta->getKey());
        Bus::assertDispatchedTimes(SyncAccountMetricsJob::class, 1);

        $this->artisan('metrics:sync-posts')->expectsOutputToContain('1 post(s) enfileirado(s)')->assertSuccessful();

        Bus::assertDispatched(SyncPostMetricsJob::class, fn (SyncPostMetricsJob $j) => $j->postId === $recente->getKey());
        Bus::assertDispatchedTimes(SyncPostMetricsJob::class, 1);

        foreach ([$antigo, $semMedia, $contaExpirada, $agendado] as $post) {
            Bus::assertNotDispatched(SyncPostMetricsJob::class, fn (SyncPostMetricsJob $j) => $j->postId === $post->getKey());
        }
    }

    public function test_job_da_conta_grava_uma_linha_por_dia_e_atualiza_ao_repetir(): void
    {
        Http::fake([
            $this->urlUser('?fields=*') => $this->fixtureResponse('account_fields'),
            $this->urlUser('/insights?metric=reach%2Cimpressions*') => $this->fixtureResponse('account_insights_series'),
            $this->urlUser('/insights?metric=profile_views%2Cwebsite_clicks*') => $this->fixtureResponse('account_insights_total_value'),
        ]);
        $dia = now()->subDay()->toDateString();

        (new SyncAccountMetricsJob($this->conta->getKey(), $dia))->handle(app(TenantContext::class), app(InstagramInsights::class));
        (new SyncAccountMetricsJob($this->conta->getKey(), $dia))->handle(app(TenantContext::class), app(InstagramInsights::class));

        $linha = MetricAccountDaily::query()->where('social_account_id', $this->conta->getKey())->sole();
        $this->assertSame($dia, $linha->date->toDateString());
        $this->assertSame(4321, $linha->followers);
        $this->assertSame(1530, $linha->reach);
        $this->assertNull($linha->impressions);
        $this->assertSame(87, $linha->profile_views);
        $this->assertNotNull($this->conta->fresh()->last_synced_at);
    }

    public function test_job_do_post_grava_a_coleta_do_dia_e_token_revogado_marca_a_conta(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'?fields=*' => $this->fixtureResponse('media_fields'),
            'graph.instagram.com/v23.0/'.self::MEDIA_ID.'/insights*' => $this->fixtureResponse('media_insights_feed'),
        ]);
        $post = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => self::MEDIA_ID]);

        (new SyncPostMetricsJob($post->getKey()))->handle(app(TenantContext::class), app(InstagramInsights::class));
        (new SyncPostMetricsJob($post->getKey()))->handle(app(TenantContext::class), app(InstagramInsights::class));

        $metrica = MetricPost::query()->where('post_id', $post->getKey())->sole();
        $this->assertSame(3200, $metrica->reach);
        $this->assertSame(240, $metrica->likes);
        $this->assertEqualsWithDelta(9.75, (float) $metrica->engagement_rate, 0.001);
        $this->assertSame(now()->startOfDay()->toDateTimeString(), $metrica->collected_at->toDateTimeString());

        // Amanhã: novo ponto da série.
        $this->travel(1)->day();
        (new SyncPostMetricsJob($post->getKey()))->handle(app(TenantContext::class), app(InstagramInsights::class));
        $this->assertSame(2, MetricPost::query()->where('post_id', $post->getKey())->count());
        $this->assertSame(3200, $post->fresh()->latestMetric->reach);

        // Outro post, cujas chamadas caem em 190: a conta é marcada para reconectar.
        Http::fake(['graph.instagram.com/v23.0/999*' => $this->fixtureResponse('error_190', 400)]);
        $revogado = Post::factory()->published()->create(['client_id' => $this->cliente->getKey(), 'social_account_id' => $this->conta->getKey(), 'external_post_id' => '999']);
        (new SyncPostMetricsJob($revogado->getKey()))->handle(app(TenantContext::class), app(InstagramInsights::class));

        $this->assertSame(ConnectionStatus::Expired, $this->conta->fresh()->connection_status);
        $this->assertStringContainsString('Reconectar', (string) $this->conta->fresh()->last_error);
    }

    public function test_agendamentos_de_metricas_e_relatorio_estao_registrados_sem_schedule_command(): void
    {
        $eventos = collect(app(Schedule::class)->events());

        foreach (['coletar-metricas-contas' => '10 4 * * *', 'coletar-metricas-posts' => '40 4 * * *', 'relatorios-mensais' => '0 9 1 * *'] as $nome => $cron) {
            $evento = $eventos->first(fn ($e) => $e->description === $nome);

            $this->assertNotNull($evento, "Agendamento {$nome} ausente em routes/console.php");
            $this->assertInstanceOf(CallbackEvent::class, $evento, "{$nome} precisa ser Schedule::call, não Schedule::command");
            $this->assertSame($cron, $evento->expression);
            $this->assertTrue($evento->withoutOverlapping);
        }
    }
}
