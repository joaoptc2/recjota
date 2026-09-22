<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Models\SocialAccount;
use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\Instagram\InstagramClient;
use App\Services\Integrations\Instagram\InstagramPublisher;
use App\Services\Integrations\SocialPublisherInterface;
use App\Support\DataObjects\MediaContainerData;
use App\Support\Enums\ContainerStatusCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Endpoints de mídia do Instagram (Seção 7.1.4 / 7.1.5), consumidos pelo motor
 * de publicação através da SocialPublisherInterface.
 */
class InstagramPublisherTest extends TestCase
{
    use InstagramFixtures;
    use RefreshDatabase;

    private SocialAccount $conta;

    private SocialPublisherInterface $publisher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInstagram();

        $this->conta = SocialAccount::factory()->create([
            'external_id' => '17841405793187218',
            'access_token' => 'IGAAtokenDaContaParaPublicar0000000000000000',
        ]);

        $this->publisher = new InstagramPublisher(new InstagramClient);
    }

    public function test_cria_container_de_imagem_com_os_parametros_da_graph_api(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17841405793187218/media' => $this->fixtureResponse('media_container')]);

        $id = $this->publisher->createContainer(
            $this->conta,
            MediaContainerData::image('https://recjota.test/media-tmp/abc.jpg', 'Legenda #teste'),
        );

        $this->assertSame('17889455560051444', $id);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->isForm()
            && $r['image_url'] === 'https://recjota.test/media-tmp/abc.jpg'
            && $r['caption'] === 'Legenda #teste'
            && $r['access_token'] === 'IGAAtokenDaContaParaPublicar0000000000000000'
            && ! isset($r['media_type']));
    }

    public function test_reels_e_carrossel_montam_o_payload_certo(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17841405793187218/media' => $this->fixtureResponse('media_container')]);

        $this->publisher->createContainer($this->conta, MediaContainerData::reel('https://x/v.mp4', 'Reel', 'https://x/c.jpg', 1500, true));
        $this->publisher->createContainer($this->conta, MediaContainerData::carouselItem(imageUrl: 'https://x/1.jpg'));
        $this->publisher->createContainer($this->conta, MediaContainerData::carousel(['111', '222'], 'Carrossel'));
        $this->publisher->createContainer($this->conta, MediaContainerData::story(videoUrl: 'https://x/s.mp4'));

        Http::assertSentInOrder([
            fn (Request $r) => $r['media_type'] === 'REELS' && $r['video_url'] === 'https://x/v.mp4'
                && $r['cover_url'] === 'https://x/c.jpg' && (int) $r['thumb_offset'] === 1500 && (bool) $r['share_to_feed'] === true,
            fn (Request $r) => ! isset($r['media_type']) && (bool) $r['is_carousel_item'] === true && $r['image_url'] === 'https://x/1.jpg',
            fn (Request $r) => $r['media_type'] === 'CAROUSEL' && $r['children'] === '111,222' && $r['caption'] === 'Carrossel',
            fn (Request $r) => $r['media_type'] === 'STORIES' && $r['video_url'] === 'https://x/s.mp4' && ! isset($r['caption']),
        ]);
    }

    public function test_consulta_status_do_container(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/17889455560051444?*' => Http::sequence()
                ->push($this->fixture('container_status_in_progress'))
                ->push($this->fixture('container_status_finished'))
                ->push($this->fixture('container_status_error')),
        ]);

        $andamento = $this->publisher->containerStatus($this->conta, '17889455560051444');
        $pronto = $this->publisher->containerStatus($this->conta, '17889455560051444');
        $erro = $this->publisher->containerStatus($this->conta, '17889455560051444');

        $this->assertSame(ContainerStatusCode::InProgress, $andamento->code);
        $this->assertFalse($andamento->isReady());
        $this->assertTrue($pronto->isReady());
        $this->assertTrue($erro->isFailed());
        $this->assertStringContainsString('too long', (string) $erro->message);

        Http::assertSent(fn (Request $r) => $r['fields'] === 'status_code,status');
    }

    public function test_publica_e_obtem_permalink(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/17841405793187218/media_publish' => $this->fixtureResponse('media_publish'),
            'graph.instagram.com/v23.0/17895695668004550?*' => $this->fixtureResponse('permalink'),
        ]);

        $mediaId = $this->publisher->publish($this->conta, '17889455560051444');
        $link = $this->publisher->permalink($this->conta, $mediaId);

        $this->assertSame('17895695668004550', $mediaId);
        $this->assertSame('https://www.instagram.com/p/CxYz123AbCd/', $link);

        Http::assertSent(fn (Request $r) => str_ends_with(strtok($r->url(), '?'), '/media_publish') && $r['creation_id'] === '17889455560051444');
    }

    public function test_permalink_ausente_vira_null_e_nao_string_inventada(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17895695668004550?*' => Http::response(['id' => '17895695668004550'])]);

        $this->assertNull($this->publisher->permalink($this->conta, '17895695668004550'));
    }

    public function test_cria_comentario(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17895695668004550/comments' => $this->fixtureResponse('comment')]);

        $id = $this->publisher->createComment($this->conta, '17895695668004550', '#hashtags #no #comentario');

        $this->assertSame('17870913679156914', $id);
        Http::assertSent(fn (Request $r) => $r['message'] === '#hashtags #no #comentario');
    }

    public function test_consulta_cota_de_publicacao(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17841405793187218/content_publishing_limit*' => $this->fixtureResponse('content_publishing_limit')]);

        $cota = $this->publisher->publishingLimit($this->conta);

        $this->assertSame(3, $cota->quotaUsage);
        $this->assertSame(50, $cota->quotaTotal);
        $this->assertSame(47, $cota->remaining());
        $this->assertFalse($cota->isExhausted());

        Http::assertSent(fn (Request $r) => $r['fields'] === 'quota_usage,config');
    }

    public function test_midia_rejeitada_e_erro_permanente_com_mensagem_acionavel(): void
    {
        Http::fake(['graph.instagram.com/v23.0/17841405793187218/media' => $this->fixtureResponse('error_100_media', 400)]);

        try {
            $this->publisher->createContainer($this->conta, MediaContainerData::image('https://x/9x16.jpg'));
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isPermanent());
            $this->assertSame(100, $e->apiCode);
            $this->assertSame(2207009, $e->apiSubcode);
            $this->assertSame('AbT7nQkH2xLp1VdW9sMoEr3', $e->traceId);
            $this->assertStringContainsString('aspect ratio', (string) $e->userMessage);
            $this->assertStringContainsString('Revise a mídia', $e->actionableMessage());
        }
    }

    public function test_permissao_negada_e_permanente_e_rate_limit_e_transitorio(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/17895695668004550/comments' => $this->fixtureResponse('error_10_permission', 403),
            'graph.instagram.com/v23.0/17841405793187218/media_publish' => $this->fixtureResponse('error_4', 400),
        ]);

        try {
            $this->publisher->createComment($this->conta, '17895695668004550', 'oi');
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isPermanent());
            $this->assertTrue($e->isPermissionDenied());
        }

        try {
            $this->publisher->publish($this->conta, '1');
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertFalse($e->isPermanent());
            $this->assertTrue($e->isRateLimit());
        }
    }

    public function test_5xx_e_falha_de_rede_sao_transitorios(): void
    {
        Http::fake([
            'graph.instagram.com/v23.0/17841405793187218/media_publish' => Http::response('<html>Bad Gateway</html>', 502),
            'graph.instagram.com/v23.0/17841405793187218/media' => fn () => throw new ConnectionException('cURL error 7: Failed to connect'),
        ]);

        try {
            $this->publisher->publish($this->conta, '1');
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertFalse($e->isPermanent());
            $this->assertSame(502, $e->httpStatus);
        }

        try {
            $this->publisher->createContainer($this->conta, MediaContainerData::image('https://x/a.jpg'));
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertFalse($e->isPermanent());
            $this->assertTrue($e->network);
        }
    }

    public function test_conta_sem_token_falha_antes_de_chamar_a_api(): void
    {
        Http::fake();
        $semToken = SocialAccount::factory()->create(['access_token' => null]);

        try {
            $this->publisher->publishingLimit($semToken);
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->isTokenInvalid());
        }

        Http::assertNothingSent();
    }
}
