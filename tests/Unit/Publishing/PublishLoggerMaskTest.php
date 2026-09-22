<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Services\Publishing\PublishLogger;
use PHPUnit\Framework\TestCase;

class PublishLoggerMaskTest extends TestCase
{
    public function test_mascara_por_chave_e_por_valor_em_qualquer_profundidade(): void
    {
        $token = 'IGAAtokenDaContaParaPublicar0000000000000000';
        $logger = new PublishLogger;

        $saida = $logger->mask([
            'access_token' => $token,
            'image_url' => 'https://x/y.jpg',
            'nested' => ['client_secret' => 'segredo-longo-do-app', 'texto' => "erro com {$token} no meio"],
            'numero' => 42,
        ], [$token]);

        $this->assertSame('IGAA…0000', $saida['access_token']);
        $this->assertSame('https://x/y.jpg', $saida['image_url']);
        $this->assertSame('segr…-app', $saida['nested']['client_secret']);
        $this->assertSame('erro com IGAA…0000 no meio', $saida['nested']['texto']);
        $this->assertSame(42, $saida['numero']);
        $this->assertStringNotContainsString($token, json_encode($saida, JSON_THROW_ON_ERROR));
    }
}
