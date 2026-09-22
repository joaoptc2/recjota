<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Jobs\Publishing\CheckContainerStatusJob;
use App\Jobs\Publishing\CreateContainerJob;
use App\Notifications\PostPublishFailed;
use App\Support\Enums\ConnectionStatus;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PublishStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Falhas do motor (Seção 8.3): permanente não tenta de novo e gera alerta
 * acionável; transitório entra no backoff 1m/5m/15m/1h/4h e desiste na 5ª.
 */
class PublishFailureHandlingTest extends TestCase
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

    public function test_erro_190_falha_de_vez_com_instrucao_para_reconectar(): void
    {
        Notification::fake();
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => $this->fixtureResponse('error_190', 400),
        ]);
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertNull($post->next_attempt_at);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertNull($post->locked_at);
        $this->assertStringContainsStringIgnoringCase('reconecte', (string) $post->last_error);
        $this->assertStringContainsString($this->conta->handle(), (string) $post->last_error);
        $this->assertStringNotContainsString(self::TOKEN, (string) $post->last_error);
        $this->assertNull($post->postMedia->first()->mediaAsset->fresh()->public_temp_path);

        // A conta inteira perdeu o acesso: fica marcada para os próximos posts.
        $this->assertSame(ConnectionStatus::Expired, $this->conta->fresh()->connection_status);

        Notification::assertSentTo($this->gestor, PostPublishFailed::class, fn (PostPublishFailed $n) => $n->stage === PublishStage::Container
            && str_contains(strtolower($n->reason), 'reconecte'));
        Notification::assertSentTo($this->autor, PostPublishFailed::class);
        Bus::assertNotDispatched(CheckContainerStatusJob::class);

        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();

        $log = DB::table('publish_logs')->where('post_id', $post->getKey())->where('stage', 'container')->first();
        $this->assertNotNull($log);
        $this->assertSame(0, (int) $log->succeeded);
        $this->assertStringNotContainsString(self::TOKEN, (string) $log->request);
        $this->assertSame(190, json_decode((string) $log->response, true)['error']['code']);
    }

    public function test_erro_5xx_entra_no_backoff_e_desiste_na_sexta_tentativa(): void
    {
        Notification::fake();
        Bus::fake([CheckContainerStatusJob::class]);
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => Http::response(['error' => ['message' => 'An unknown error occurred', 'code' => 1]], 500),
        ]);
        $inicio = Carbon::parse('2026-09-21 12:00:00');
        $this->travelTo($inicio);
        $post = $this->postPronto();

        // 1ª tentativa: +1 min.
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());
        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame(1, $post->publish_attempts);
        $this->assertSame('2026-09-21 12:01:00', $post->next_attempt_at->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('instável', (string) $post->last_error);
        Notification::assertNothingSent();

        // Ainda não venceu o backoff: nem o despachante nem o job pegam.
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();
        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());
        $this->assertSame(1, $post->fresh()->publish_attempts);

        // 2ª tentativa: +5 min.
        $this->travelTo($inicio->copy()->addMinutes(1));
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('1 despachado(s)')->assertSuccessful();
        $post->refresh();
        $this->assertSame(2, $post->publish_attempts);
        $this->assertSame('2026-09-21 12:06:00', $post->next_attempt_at->format('Y-m-d H:i:s'));

        // 3ª: +15 min; 4ª: +1 h.
        $this->travelTo($post->next_attempt_at);
        $this->artisan('posts:dispatch-due')->assertSuccessful();
        $post->refresh();
        $this->assertSame(3, $post->publish_attempts);
        $this->assertSame('2026-09-21 12:21:00', $post->next_attempt_at->format('Y-m-d H:i:s'));

        $this->travelTo($post->next_attempt_at);
        $this->artisan('posts:dispatch-due')->assertSuccessful();
        $post->refresh();
        $this->assertSame(4, $post->publish_attempts);
        $this->assertSame('2026-09-21 13:21:00', $post->next_attempt_at->format('Y-m-d H:i:s'));
        Notification::assertNothingSent();

        // 5ª: +4 h — o último degrau do backoff é usado, não pulado.
        $this->travelTo($post->next_attempt_at);
        $this->artisan('posts:dispatch-due')->assertSuccessful();
        $post->refresh();
        $this->assertSame(5, $post->publish_attempts);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertSame('2026-09-21 17:21:00', $post->next_attempt_at->format('Y-m-d H:i:s'));
        Notification::assertNothingSent();

        // 6ª: desiste, avisa, não agenda mais nada.
        $this->travelTo($post->next_attempt_at);
        $this->artisan('posts:dispatch-due')->assertSuccessful();
        $post->refresh();
        $this->assertSame(6, $post->publish_attempts);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertNull($post->next_attempt_at);
        $this->assertStringContainsString('Falhou 6 vezes', (string) $post->last_error);
        Notification::assertSentTo($this->gestor, PostPublishFailed::class);

        $this->travel(1)->day();
        $this->artisan('posts:dispatch-due')->expectsOutputToContain('0 despachado(s)')->assertSuccessful();
        $this->assertSame(6, DB::table('publish_logs')->where('post_id', $post->getKey())->where('stage', 'container')->count());
    }

    public function test_falha_de_rede_e_transitoria(): void
    {
        Notification::fake();
        Http::fake([
            $this->urlQuota() => $this->fixtureResponse('content_publishing_limit'),
            $this->urlMedia() => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
        ]);
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertFalse($post->last_error_is_permanent);
        $this->assertNotNull($post->next_attempt_at);
        Notification::assertNothingSent();
    }

    public function test_midia_original_ausente_e_falha_permanente(): void
    {
        Notification::fake();
        Http::fake([$this->urlQuota() => $this->fixtureResponse('content_publishing_limit')]);
        $post = $this->postPronto();
        $post->postMedia->first()->mediaAsset->forceFill(['local_path' => 'clients/x/sumiu.jpg'])->save();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        $post->refresh();
        $this->assertSame(PostStatus::Failed, $post->status);
        $this->assertTrue($post->last_error_is_permanent);
        $this->assertStringContainsString('não foi encontrado', (string) $post->last_error);
        $this->assertSame(0, $this->chamadasPara('/media'));
        Notification::assertSentTo($this->gestor, PostPublishFailed::class);
    }

    public function test_conta_desconectada_falha_sem_chamar_a_api(): void
    {
        Notification::fake();
        Http::fake();
        $this->conta->forceFill(['connection_status' => ConnectionStatus::Expired])->save();
        $post = $this->postPronto();

        (new CreateContainerJob($post->getKey(), 1))->handle(...$this->deps());

        Http::assertNothingSent();
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertTrue($post->fresh()->last_error_is_permanent);
        $this->assertStringContainsStringIgnoringCase('reconecte', (string) $post->fresh()->last_error);
    }

    public function test_email_de_falha_renderiza_o_motivo_sem_token(): void
    {
        $post = $this->postPronto(['status' => PostStatus::Failed, 'last_error' => 'O acesso da conta foi revogado. Reconecte a conta em Integrações.']);

        $html = (string) (new PostPublishFailed($post, (string) $post->last_error, PublishStage::Container))->toMail($this->gestor)->render();

        $this->assertStringContainsString('Reconecte a conta', $html);
        $this->assertStringContainsString(route('painel.posts.show', $post), $html);
        $this->assertStringNotContainsString(self::TOKEN, $html);
    }

    /** @return array<int, object> */
    private function deps(): array
    {
        $params = (new \ReflectionMethod(CreateContainerJob::class, 'handle'))->getParameters();

        return array_map(fn (\ReflectionParameter $p) => app($p->getType()->getName()), $params);
    }
}
