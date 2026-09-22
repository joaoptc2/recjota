<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Support\SecretMask;
use PHPUnit\Framework\TestCase;

class SecretMaskTest extends TestCase
{
    public function test_mostra_so_os_4_primeiros_e_4_ultimos_caracteres(): void
    {
        $this->assertSame('IGQV…wxyz', SecretMask::mask('IGQVJXabcdefghijklmnopqrstuvwxyz'));
    }

    public function test_valor_curto_vira_mascara_completa(): void
    {
        $this->assertSame('********', SecretMask::mask('12345678'));
        $this->assertSame('***', SecretMask::mask('abc'));
        $this->assertSame('', SecretMask::mask(null));
        $this->assertSame('', SecretMask::mask(''));
    }

    public function test_scrub_substitui_o_segredo_em_texto_e_em_url_codificada(): void
    {
        $token = 'IGQVJX+abc/def==ghijklmnop';
        $texto = 'url?access_token='.urlencode($token).' body: '.$token.' outro: x';

        $limpo = SecretMask::scrub($texto, [$token, null, '']);

        $this->assertStringNotContainsString($token, $limpo);
        $this->assertStringNotContainsString(urlencode($token), $limpo);
        $this->assertSame(2, substr_count($limpo, 'IGQV…mnop'));
        $this->assertStringContainsString('outro: x', $limpo);
    }
}
