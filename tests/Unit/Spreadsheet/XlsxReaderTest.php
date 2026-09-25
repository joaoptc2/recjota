<?php

declare(strict_types=1);

namespace Tests\Unit\Spreadsheet;

use App\Support\Spreadsheet\XlsxReader;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Leitor de .xlsx em PHP puro: abas, tipos, datas e limites. */
class XlsxReaderTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/enfermagem/busca-fonada-exemplo.xlsx';

    public function test_lista_as_abas_e_encontra_por_nome_sem_diferenciar_caixa(): void
    {
        $reader = XlsxReader::open(self::FIXTURE);

        $this->assertSame(['INÍCIO', 'PACIENTES', 'LISTAS'], $reader->sheetNames());
        $this->assertTrue($reader->hasSheet('pacientes'));
        $this->assertFalse($reader->hasSheet('EXAMES'));
    }

    public function test_le_o_cabecalho_e_as_linhas_com_os_tipos_certos(): void
    {
        $linhas = iterator_to_array(XlsxReader::open(self::FIXTURE)->rows('PACIENTES'));

        $this->assertSame('PACIENTE', $linhas[4]['D']);
        $this->assertSame("DATA DA\nCIRURGIA", $linhas[4]['B']);

        $primeira = $linhas[5];
        $this->assertSame(1, $primeira['A']);
        $this->assertInstanceOf(Carbon::class, $primeira['B']);
        $this->assertSame('2026-06-01 07:15:00', $primeira['B']->format('Y-m-d H:i:s'), 'Data com hora vira Carbon sem fuso aplicado');
        $this->assertSame('31999990001', $primeira['E'], 'Celular digitado como texto continua texto');
        $this->assertSame(' paciente.um@exemplo.test', $primeira['G'], 'Espaço inicial é preservado; quem limpa é o importador');
        $this->assertSame('Sim', $primeira['H']);
        $this->assertSame('2026-07-01', $primeira['L']->format('Y-m-d'));
        $this->assertSame('ATENDEU', $primeira['M']);
        $this->assertArrayNotHasKey('N', $primeira, 'Célula vazia não aparece');

        $this->assertSame('3199999-0004', $linhas[8]['E']);
        $this->assertArrayNotHasKey('H', $linhas[8], 'Prótese sem informação fica ausente');
        $this->assertSame('SIM - COM PUS (GROSSA, ESVERDEADA, CHEIRO RUIM)', $linhas[7]['V']);
    }

    public function test_respeita_o_limite_de_linhas_e_aba_inexistente_explica(): void
    {
        $reader = XlsxReader::open(self::FIXTURE);

        $this->assertCount(3, iterator_to_array($reader->rows('PACIENTES', 3)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A aba "EXAMES" não existe');
        iterator_to_array($reader->rows('EXAMES'));
    }

    public function test_arquivo_que_nao_e_xlsx_e_recusado_com_mensagem(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nao-xlsx');
        file_put_contents($tmp, 'isto é um texto qualquer');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('não é um .xlsx válido');
            XlsxReader::open($tmp);
        } finally {
            @unlink($tmp);
        }
    }
}
