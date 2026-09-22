<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Models\CloudConnection;
use App\Models\MediaAsset;
use App\Models\PostMedia;
use App\Notifications\CloudConnectionExpired;
use App\Services\Media\PublicMediaBridge;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\MediaSource;
use App\Support\Enums\PostStatus;
use App\Support\Exceptions\MediaBridgeFailed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Integrations\CloudFixtures;
use Tests\TestCase;

/**
 * Critério de aceite da Fase 5: uma imagem escolhida direto do Drive do
 * cliente, anexada a um post, é publicada no Instagram sem download manual.
 * A ponte baixa o original na hora de publicar e o apaga depois.
 */
class CloudMediaPublishingTest extends TestCase
{
    use CloudFixtures;
    use PublishingSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpPublishing();
        $this->configureCloud();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory((string) config('agency.cloud_temp.path'));
        $this->tearDownPublishing();

        parent::tearDown();
    }

    private function assetDaNuvem(CloudConnection $conexao, string $fileId, string $nome = 'banner-promocao.jpg'): MediaAsset
    {
        return MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'source' => $conexao->provider->mediaSource(),
            'external_account_id' => $conexao->getKey(),
            'external_file_id' => $fileId,
            'filename' => $nome,
            'local_path' => null,
        ]);
    }

    public function test_imagem_do_google_drive_e_publicada_de_ponta_a_ponta_sem_download_manual(): void
    {
        Notification::fake();
        Http::fake([
            'www.googleapis.com/drive/v3/files/'.self::GOOGLE_FILE_ID.'?alt=media*' => Http::response($this->jpegBinary(), 200, ['Content-Type' => 'image/jpeg']),
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
            $this->urlContainer() => $this->fixtureResponse('container_status_finished'),
            $this->urlPublish() => $this->fixtureResponse('media_publish'),
            $this->urlPermalink() => $this->fixtureResponse('permalink'),
            $this->urlComments() => $this->fixtureResponse('comment'),
        ]);
        $conexao = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        $asset = $this->assetDaNuvem($conexao, self::GOOGLE_FILE_ID);
        $post = $this->postPronto(midias: 0);
        PostMedia::create(['post_id' => $post->getKey(), 'media_asset_id' => $asset->getKey(), 'position' => 0]);

        $this->artisan('posts:dispatch-due')->assertSuccessful();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $this->assertSame(self::MEDIA_ID, $post->external_post_id);

        // O Instagram recebeu a URL da ponte, não um link do Drive.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/media')
            && str_starts_with((string) $r['image_url'], 'https://agencia.example.com/media-tmp/')
            && str_ends_with((string) $r['image_url'], '.jpg'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'alt=media'));

        // Ponte liberada e original nunca guardado.
        $asset->refresh();
        $this->assertNull($asset->public_temp_path);
        $this->assertNull($asset->local_path);
        $this->assertDirectoryDoesNotExist($this->ponte.'/'.$asset->ulid);
    }

    public function test_video_do_onedrive_reaproveita_o_download_quando_o_job_e_reexecutado(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/items/'.self::ONEDRIVE_ITEM_ID.'/content' => Http::response($this->jpegBinary(), 200),
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);
        $conexao = CloudConnection::factory()->onedrive()->create(['client_id' => $this->cliente->getKey()]);
        $asset = $this->assetDaNuvem($conexao, self::ONEDRIVE_ITEM_ID, 'vitrine.jpg');

        $ponte = app(PublicMediaBridge::class);
        $url1 = $ponte->publish($asset);
        $url2 = $ponte->publish($asset->fresh());

        $this->assertSame($url1, $url2);
        $this->assertSame(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/content'))->count());
    }

    public function test_conexao_expirada_ou_arquivo_sumido_falha_de_vez_com_instrucao_para_reconectar(): void
    {
        Notification::fake();
        Http::fake([
            'www.googleapis.com/drive/v3/files/sumiu?alt=media*' => $this->cloudResponse('google', 'file_not_found', 404),
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('media_container'),
        ]);

        $quebrada = CloudConnection::factory()->expired()->create(['client_id' => $this->cliente->getKey()]);
        $postQuebrado = $this->postPronto(midias: 0);
        PostMedia::create(['post_id' => $postQuebrado->getKey(), 'media_asset_id' => $this->assetDaNuvem($quebrada, self::GOOGLE_FILE_ID)->getKey(), 'position' => 0]);

        $ok = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        $postSumido = $this->postPronto(midias: 0);
        PostMedia::create(['post_id' => $postSumido->getKey(), 'media_asset_id' => $this->assetDaNuvem($ok, 'sumiu')->getKey(), 'position' => 0]);

        $this->artisan('posts:dispatch-due')->assertSuccessful();

        $postQuebrado->refresh();
        $this->assertSame(PostStatus::Failed, $postQuebrado->status);
        $this->assertTrue($postQuebrado->last_error_is_permanent);
        $this->assertStringContainsString('Reconectar', (string) $postQuebrado->last_error);

        $postSumido->refresh();
        $this->assertSame(PostStatus::Failed, $postSumido->status);
        $this->assertTrue($postSumido->last_error_is_permanent);
        $this->assertStringContainsString('não existe mais no Google Drive', (string) $postSumido->last_error);

        $this->assertSame(0, $this->chamadasPara('/media'));
        $this->assertSame(ConnectionStatus::Expired, $quebrada->fresh()->status);
    }

    public function test_renovacao_diaria_das_conexoes_de_nuvem_avisa_quando_o_acesso_foi_perdido(): void
    {
        Notification::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => $this->cloudResponse('google', 'token_refreshed'),
            'login.microsoftonline.com/common/oauth2/v2.0/token' => $this->cloudResponse('microsoft', 'invalid_grant', 400),
        ]);
        $google = CloudConnection::factory()->create(['client_id' => $this->cliente->getKey()]);
        $microsoft = CloudConnection::factory()->onedrive()->create(['client_id' => $this->cliente->getKey()]);
        $jaExpirada = CloudConnection::factory()->expired()->create(['client_id' => $this->cliente->getKey()]);

        $this->artisan('cloud:refresh-tokens')
            ->expectsOutputToContain('1 renovada(s), 1 expirada(s), 0 adiada(s).')
            ->assertSuccessful();

        $this->assertStringContainsString('tokenRenovadoDoGoogle', $google->fresh()->access_token);
        $this->assertSame(ConnectionStatus::Expired, $microsoft->fresh()->status);
        $this->assertSame(ConnectionStatus::Expired, $jaExpirada->fresh()->status);

        Notification::assertSentTo($this->gestor, CloudConnectionExpired::class, fn ($n) => $n->cloudConnection->is($microsoft));
        Notification::assertCount(1);

        $this->assertNotNull(collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'renovar-tokens-nuvem'));
    }

    public function test_source_sem_conexao_falha_com_mensagem_e_source_upload_continua_igual(): void
    {
        $orfao = MediaAsset::factory()->create([
            'client_id' => $this->cliente->getKey(),
            'source' => MediaSource::OneDrive,
            'external_account_id' => null,
            'external_file_id' => 'x',
            'local_path' => null,
        ]);

        try {
            app(PublicMediaBridge::class)->publish($orfao);
            $this->fail('Esperava MediaBridgeFailed');
        } catch (MediaBridgeFailed $e) {
            $this->assertStringContainsString('conexão com essa conta não existe mais', $e->getMessage());
        }

        $upload = $this->imagem('local.jpg');
        $this->assertStringStartsWith('https://agencia.example.com/media-tmp/', app(PublicMediaBridge::class)->publish($upload));
    }
}
