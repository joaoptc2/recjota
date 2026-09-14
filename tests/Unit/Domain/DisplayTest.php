<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Support\Display;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Conversão UTC ↔ fuso do cliente acontece só na apresentação (R2 / Seção 8.4). */
class DisplayTest extends TestCase
{
    public function test_converte_utc_para_o_fuso_de_brasilia_na_exibicao(): void
    {
        $utc = Carbon::parse('2026-03-15 21:00:00', 'UTC');

        $this->assertSame('15/03/2026 18:00', Display::datetime($utc, 'America/Sao_Paulo'));
    }

    public function test_texto_auxiliar_mostra_local_e_utc_lado_a_lado(): void
    {
        $utc = Carbon::parse('2026-03-15 21:00:00', 'UTC');

        $this->assertSame(
            '15/03/2026 18:00 (horário de Brasília) — 15/03/2026 21:00 UTC',
            Display::localWithUtc($utc, 'America/Sao_Paulo'),
        );
    }

    public function test_data_digitada_no_fuso_do_cliente_e_gravada_em_utc(): void
    {
        $utc = Display::toUtc('2026-03-15 18:00', 'America/Sao_Paulo');

        $this->assertSame('UTC', $utc->timezone->getName());
        $this->assertSame('2026-03-15 21:00:00', $utc->format('Y-m-d H:i:s'));
    }

    public function test_valor_nulo_vira_travessao_em_vez_de_erro(): void
    {
        $this->assertSame('—', Display::datetime(null));
        $this->assertSame('—', display_datetime(null));
    }

    public function test_a_aplicacao_roda_em_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }
}
