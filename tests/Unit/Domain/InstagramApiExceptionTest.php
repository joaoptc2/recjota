<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Services\Integrations\Instagram\InstagramApiException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Classificação permanente/transitório que dirige o retry do motor (Seção 7.1.6). */
class InstagramApiExceptionTest extends TestCase
{
    /** @return array<string, array{int|null, int|null, bool}> */
    public static function classificacao(): array
    {
        return [
            '190 token inválido' => [190, 400, true],
            '10 permissão negada' => [10, 403, true],
            '100 parâmetro inválido' => [100, 400, true],
            '24 mídia rejeitada' => [24, 400, true],
            '36 mídia rejeitada' => [36, 400, true],
            '4 rate limit app' => [4, 400, false],
            '17 rate limit usuário' => [17, 400, false],
            '32 rate limit página' => [32, 400, false],
            '613 rate limit custom' => [613, 400, false],
            '2 indisponível' => [2, 400, false],
            '190 mas 5xx' => [190, 503, false],
            'sem código, 500' => [null, 500, false],
            'sem código, 400' => [null, 400, false],
        ];
    }

    #[DataProvider('classificacao')]
    public function test_classifica_permanente_ou_transitorio(?int $codigo, ?int $http, bool $permanente): void
    {
        $e = new InstagramApiException('x', apiCode: $codigo, httpStatus: $http);

        $this->assertSame($permanente, $e->isPermanent());
        $this->assertSame(! $permanente, $e->isTransient());
    }

    public function test_falha_de_rede_e_sempre_transitoria(): void
    {
        $e = InstagramApiException::network(new \RuntimeException('cURL error 28'));

        $this->assertFalse($e->isPermanent());
        $this->assertTrue($e->network);
    }

    public function test_mensagem_acionavel_diz_o_que_fazer(): void
    {
        $this->assertStringContainsString('Reconectar', (new InstagramApiException('x', apiCode: 190))->actionableMessage());
        $this->assertStringContainsString('permissões', (new InstagramApiException('x', apiCode: 10))->actionableMessage());
        $this->assertStringContainsString('Aguarde', (new InstagramApiException('x', apiCode: 4))->actionableMessage());
        $this->assertStringContainsString('mídia', (new InstagramApiException('x', apiCode: 36))->actionableMessage());
        $this->assertStringContainsString('instável', (new InstagramApiException('x', httpStatus: 502))->actionableMessage());
    }

    public function test_segredos_sao_mascarados_na_mensagem(): void
    {
        $e = new InstagramApiException('token IGQVJXsegredo1234567890 rejeitado', secrets: ['IGQVJXsegredo1234567890']);

        $this->assertSame('token IGQV…7890 rejeitado', $e->getMessage());
    }
}
