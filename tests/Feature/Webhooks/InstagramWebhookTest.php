<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Jobs\Webhooks\ProcessInstagramWebhookJob;
use App\Models\Client;
use App\Models\SocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Integrations\InstagramFixtures;
use Tests\TestCase;

/**
 * Webhook do Instagram (Seção 7.1.6): verificação por token, assinatura
 * HMAC obrigatória e nada de processamento síncrono.
 */
class InstagramWebhookTest extends TestCase
{
    use InstagramFixtures;
    use RefreshDatabase;

    private const VERIFY_TOKEN = 'token-de-verificacao-cadastrado-na-meta';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureInstagram();
        config(['services.instagram.webhook_verify_token' => self::VERIFY_TOKEN]);
    }

    public function test_get_responde_o_challenge_em_texto_puro_com_o_token_certo(): void
    {
        $resposta = $this->get(route('webhooks.instagram.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => self::VERIFY_TOKEN,
            'hub_challenge' => '1158201444',
        ]));

        $resposta->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('1158201444', $resposta->getContent());
    }

    public function test_get_com_token_errado_devolve_403(): void
    {
        $this->get(route('webhooks.instagram.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'outro-token',
            'hub_challenge' => '1158201444',
        ]))->assertForbidden();
    }

    public function test_get_sem_token_configurado_nunca_confirma(): void
    {
        config(['services.instagram.webhook_verify_token' => '']);

        $this->get(route('webhooks.instagram.verify', [
            'hub_mode' => 'subscribe',
            'hub_verify_token' => '',
            'hub_challenge' => '1',
        ]))->assertForbidden();
    }

    public function test_post_assinado_enfileira_o_job_e_responde_200_em_texto_puro(): void
    {
        Queue::fake();

        $corpo = $this->rawFixture('webhook_comments');

        $resposta = $this->postWebhook($corpo, $this->sign($corpo));

        $resposta->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
        $this->assertSame('EVENT_RECEIVED', $resposta->getContent());

        Queue::assertPushed(ProcessInstagramWebhookJob::class, function (ProcessInstagramWebhookJob $job): bool {
            return $job->payload['object'] === 'instagram'
                && $job->payload['entry'][0]['changes'][0]['field'] === 'comments';
        });
    }

    public function test_post_com_assinatura_invalida_devolve_403_sem_enfileirar(): void
    {
        Queue::fake();

        $corpo = $this->rawFixture('webhook_comments');

        $this->postWebhook($corpo, 'sha256='.hash_hmac('sha256', $corpo, 'segredo-errado'))->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_post_sem_assinatura_devolve_403_sem_enfileirar(): void
    {
        Queue::fake();

        $this->postWebhook($this->rawFixture('webhook_comments'), null)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_post_com_corpo_alterado_depois_de_assinado_devolve_403(): void
    {
        Queue::fake();

        $corpo = $this->rawFixture('webhook_comments');
        $assinatura = $this->sign($corpo);

        $this->postWebhook(str_replace('Adorei', 'Odiei', $corpo), $assinatura)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_job_registra_o_evento_no_log_e_no_activity_log_ligado_a_conta(): void
    {
        Log::spy();

        $cliente = Client::factory()->configured()->create();
        $conta = SocialAccount::factory()->create([
            'client_id' => $cliente->getKey(),
            'external_id' => '17841405822304914',
        ]);

        (new ProcessInstagramWebhookJob($this->fixture('webhook_comments')))->handle(tenant());

        Log::shouldHaveReceived('info')->once()->with('Webhook do Instagram recebido', \Mockery::on(
            fn (array $ctx) => $ctx['campo'] === 'comments' && $ctx['social_account_id'] === $conta->getKey(),
        ));

        $atividade = Activity::query()->where('log_name', 'webhook')->latest('id')->first();

        $this->assertNotNull($atividade);
        $this->assertSame('instagram.comments', $atividade->event);
        $this->assertTrue($atividade->subject->is($conta));
        $this->assertSame('Adorei o post!', $atividade->properties['valor']['text']);
    }

    private function rawFixture(string $nome): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/instagram/{$nome}.json"));
    }

    private function sign(string $corpo): string
    {
        return 'sha256='.hash_hmac('sha256', $corpo, (string) config('services.instagram.app_secret'));
    }

    private function postWebhook(string $corpo, ?string $assinatura)
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

        if ($assinatura !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = $assinatura;
        }

        return $this->call('POST', route('webhooks.instagram.receive'), [], [], [], $headers, $corpo);
    }
}
