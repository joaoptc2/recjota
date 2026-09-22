<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations;

use App\Services\Integrations\Instagram\InstagramApiException;
use App\Services\Integrations\Instagram\InstagramClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Comportamentos transversais do cliente: cabeçalhos de uso no log, aviso
 * acima de 80% e mascaramento de token em qualquer saída (Seção 10).
 */
class InstagramClientTest extends TestCase
{
    use InstagramFixtures;

    private const TOKEN = 'IGAAtokenSecretoQueNaoPodeAparecerInteiroNoLog12345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureInstagram();
    }

    public function test_registra_cabecalhos_de_uso_quando_presentes(): void
    {
        $logs = &$this->capturedLogs();

        Http::fake([
            'graph.instagram.com/v23.0/me*' => $this->fixtureResponse('me', 200, [
                'X-App-Usage' => '{"call_count":28,"total_time":25,"total_cputime":25}',
                'X-Business-Use-Case-Usage' => '{"17841405793187218":[{"type":"instagram","call_count":12,"total_cputime":3,"total_time":4,"estimated_time_to_regain_access":0}]}',
            ]),
        ]);

        (new InstagramClient)->me(self::TOKEN);

        $texto = implode("\n", $logs);
        $this->assertStringContainsString('X-App-Usage', $texto);
        $this->assertStringContainsString('"call_count":28', $texto);
        $this->assertStringContainsString('X-Business-Use-Case-Usage', $texto);
        $this->assertStringNotContainsString('acima de 80%', $texto);
    }

    public function test_avisa_quando_qualquer_bucket_passa_de_80_por_cento(): void
    {
        $logs = &$this->capturedLogs();

        Http::fake([
            'graph.instagram.com/v23.0/me*' => $this->fixtureResponse('me', 200, [
                'X-App-Usage' => '{"call_count":40,"total_time":91,"total_cputime":12}',
                'X-Business-Use-Case-Usage' => '{"17841405793187218":[{"type":"instagram","call_count":85,"total_cputime":3,"total_time":4}]}',
            ]),
        ]);

        (new InstagramClient)->me(self::TOKEN);

        $avisos = array_values(array_filter($logs, fn (string $l) => str_contains($l, 'acima de 80%')));

        $this->assertCount(2, $avisos);
        $this->assertStringContainsString('"total_time":91', $avisos[0]);
        $this->assertStringContainsString('call_count":85', $avisos[1]);
        $this->assertStringNotContainsString('"total_cputime":12', $avisos[0]);
    }

    public function test_token_nunca_aparece_inteiro_no_log_nem_na_excecao(): void
    {
        $logs = &$this->capturedLogs();

        // A API às vezes ecoa parâmetros no corpo do erro: mesmo assim nada vaza.
        Http::fake([
            'graph.instagram.com/v23.0/refresh_access_token*' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token - Cannot parse access token '.self::TOKEN,
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400, ['X-App-Usage' => '{"call_count":1}']),
        ]);

        try {
            (new InstagramClient)->refreshLongLived(self::TOKEN);
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringContainsString('IGAA…2345', $e->getMessage());
            $this->assertTrue($e->isPermanent());
        }

        $texto = implode("\n", $logs);
        $this->assertNotEmpty($logs);
        $this->assertStringNotContainsString(self::TOKEN, $texto);
        $this->assertStringNotContainsString('segredo-do-app-que-nunca-vaza-em-log', $texto);
        $this->assertStringContainsString('erro da API', $texto);
    }

    public function test_falha_de_rede_com_url_contendo_token_e_mascarada(): void
    {
        $logs = &$this->capturedLogs();

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out for https://graph.instagram.com/v23.0/me?access_token='.self::TOKEN,
        ));

        try {
            (new InstagramClient)->me(self::TOKEN);
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertFalse($e->isPermanent());
            // A exceção original carrega a URL com o token: não pode ir na cadeia.
            $this->assertNull($e->getPrevious());
            $this->assertStringContainsString('ConnectionException', $e->getMessage());
        }

        $this->assertStringNotContainsString(self::TOKEN, implode("\n", $logs));
    }

    public function test_orcamento_de_tempo_reparte_o_timeout_e_falha_como_rede_quando_acaba(): void
    {
        Http::fake(['graph.instagram.com/v23.0/me*' => $this->fixtureResponse('me')]);

        $cliente = (new InstagramClient)->withTimeBudget(2.0);
        $cliente->me(self::TOKEN);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/me'));

        $esgotado = (new InstagramClient)->withTimeBudget(0.0);

        try {
            $esgotado->me(self::TOKEN);
            $this->fail('Esperava InstagramApiException');
        } catch (InstagramApiException $e) {
            $this->assertTrue($e->network);
            $this->assertFalse($e->isPermanent());
            $this->assertStringContainsString('Não foi possível falar com o Instagram', $e->actionableMessage());
        }

        // O cliente original não tem orçamento: withTimeBudget devolve uma cópia.
        (new InstagramClient)->me(self::TOKEN);
        $this->assertSame(2, Http::recorded(fn (Request $r) => str_contains($r->url(), '/me'))->count());
    }

    public function test_url_de_autorizacao_e_a_do_instagram_com_os_escopos_de_negocio(): void
    {
        $url = (new InstagramClient)->authorizationUrl('abc123');

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertStringContainsString('client_id=1234567890', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=abc123', $url);
        $this->assertStringContainsString('instagram_business_content_publish', $url);
    }

    public function test_resposta_que_nao_e_json_vira_excecao_de_dominio(): void
    {
        Http::fake(['graph.instagram.com/v23.0/me*' => Http::response('<html>Sorry, something went wrong.</html>', 200)]);

        $this->expectException(InstagramApiException::class);

        (new InstagramClient)->me(self::TOKEN);
    }

    /** @return array<int, string> */
    private function &capturedLogs(): array
    {
        $capturado = [];

        Log::listen(function (MessageLogged $evento) use (&$capturado): void {
            $capturado[] = $evento->message.' '.json_encode($evento->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        });

        return $capturado;
    }
}
