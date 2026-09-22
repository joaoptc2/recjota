<?php

declare(strict_types=1);

namespace Tests\Unit\Publishing;

use App\Support\Publishing\PublishBackoff;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class PublishBackoffTest extends TestCase
{
    public function test_degraus_de_1m_5m_15m_1h_4h(): void
    {
        $this->assertSame(1, PublishBackoff::minutesAfter(1));
        $this->assertSame(5, PublishBackoff::minutesAfter(2));
        $this->assertSame(15, PublishBackoff::minutesAfter(3));
        $this->assertSame(60, PublishBackoff::minutesAfter(4));
        $this->assertSame(240, PublishBackoff::minutesAfter(5));
    }

    public function test_fora_da_tabela_repete_o_ultimo_degrau_e_nunca_quebra(): void
    {
        $this->assertSame(240, PublishBackoff::minutesAfter(6));
        $this->assertSame(240, PublishBackoff::minutesAfter(99));
        $this->assertSame(1, PublishBackoff::minutesAfter(0));
        $this->assertSame(1, PublishBackoff::minutesAfter(-3));
    }

    public function test_proxima_tentativa_a_partir_de_um_instante(): void
    {
        $base = Carbon::parse('2026-09-21 12:00:00', 'UTC');

        $this->assertSame('2026-09-21 12:01:00', PublishBackoff::nextAttemptAt(1, $base)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 16:00:00', PublishBackoff::nextAttemptAt(5, $base)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-21 12:00:00', $base->format('Y-m-d H:i:s'), 'O instante de origem não pode ser mutado');
    }
}
