<?php

declare(strict_types=1);

namespace App\Support\Spreadsheet;

use Illuminate\Support\Carbon;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Leitor mínimo de .xlsx em PHP puro (ZipArchive + XMLReader), para importar
 * a planilha da busca fonada sem instalar PhpSpreadsheet — pacote pesado e
 * fora da lista de extensões garantidas na hospedagem (R6).
 *
 * Lê uma aba por vez, em streaming, e devolve cada linha como array
 * coluna-letra => valor já tipado: string, int/float, bool, ou Carbon (UTC)
 * quando o estilo da célula é de data. Para célula de fórmula devolve o valor
 * em cache (o que o Excel gravou), nunca a fórmula.
 */
class XlsxReader
{
    /** Formatos numéricos embutidos do Excel que representam data/hora. */
    private const BUILTIN_DATE_FORMATS = [14, 15, 16, 17, 18, 19, 20, 21, 22, 27, 28, 29, 30, 31, 32, 33, 34, 35, 36, 45, 46, 47, 50, 51, 52, 53, 54, 55, 56, 57, 58];

    private ZipArchive $zip;

    /** @var array<int, string> */
    private array $sharedStrings = [];

    /** @var array<int, bool> índice do cellXfs => é data? */
    private array $dateStyles = [];

    private bool $date1904 = false;

    /** @var array<string, string> nome da aba => caminho dentro do zip */
    private array $sheets = [];

    private function __construct(string $caminho)
    {
        $this->zip = new ZipArchive;

        if ($this->zip->open($caminho) !== true) {
            throw new RuntimeException('O arquivo não é um .xlsx válido (não abre como pacote ZIP).');
        }

        $this->loadWorkbook();
        $this->loadSharedStrings();
        $this->loadStyles();
    }

    public static function open(string $caminho): self
    {
        return new self($caminho);
    }

    public function __destruct()
    {
        if (isset($this->zip)) {
            @$this->zip->close();
        }
    }

    /** @return array<int, string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    public function hasSheet(string $nome): bool
    {
        return $this->findSheet($nome) !== null;
    }

    /**
     * Linhas da aba, na ordem, como [numero_da_linha => [coluna => valor]].
     * Células vazias não aparecem. Para de ler em $maxRows (proteção contra
     * planilhas com um milhão de linhas formatadas, como a original).
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function rows(string $sheet, int $maxRows = 5000): \Generator
    {
        $chave = $this->findSheet($sheet);

        if ($chave === null) {
            throw new RuntimeException(sprintf('A aba "%s" não existe no arquivo. Abas encontradas: %s.', $sheet, implode(', ', $this->sheetNames())));
        }

        $xml = $this->zip->getFromName($this->sheets[$chave]);

        if ($xml === false) {
            throw new RuntimeException(sprintf('Não foi possível ler a aba "%s".', $sheet));
        }

        $reader = new XMLReader;
        $reader->XML($xml);

        $lidas = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $numero = (int) $reader->getAttribute('r');
            $linha = $this->readRow($reader);

            if ($linha !== []) {
                yield $numero => $linha;
            }

            if (++$lidas >= $maxRows) {
                break;
            }
        }

        $reader->close();
    }

    // ---------------------------------------------------------------- interno

    /** @return array<string, mixed> */
    private function readRow(XMLReader $reader): array
    {
        $linha = [];

        if ($reader->isEmptyElement) {
            return $linha;
        }

        $profundidade = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $profundidade) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                continue;
            }

            $ref = (string) $reader->getAttribute('r');
            $tipo = (string) $reader->getAttribute('t');
            $estilo = $reader->getAttribute('s');
            $coluna = preg_replace('/\d+/', '', $ref) ?: '';

            $valor = $this->readCell($reader, $tipo, $estilo === null ? null : (int) $estilo);

            if ($valor !== null && $valor !== '') {
                $linha[$coluna] = $valor;
            }
        }

        return $linha;
    }

    private function readCell(XMLReader $reader, string $tipo, ?int $estilo): mixed
    {
        if ($reader->isEmptyElement) {
            return null;
        }

        $profundidade = $reader->depth;
        $v = null;
        $inline = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $profundidade) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->localName === 'v') {
                $v = $reader->readString();
            } elseif ($reader->localName === 'is') {
                $inline = $this->richText($reader->readOuterXml());
            }
        }

        return match ($tipo) {
            's' => $this->sharedStrings[(int) $v] ?? '',
            'inlineStr' => $inline ?? '',
            'str' => $v === null ? '' : (string) $v,
            'b' => $v === '1',
            'e' => null,
            default => $this->numeric($v, $estilo),
        };
    }

    private function numeric(?string $v, ?int $estilo): mixed
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (! is_numeric($v)) {
            return $v;
        }

        if ($estilo !== null && ($this->dateStyles[$estilo] ?? false)) {
            return $this->serialToDate((float) $v);
        }

        return str_contains($v, '.') || str_contains($v, 'E') || str_contains($v, 'e')
            ? (float) $v
            : (int) $v;
    }

    /** Serial do Excel (dias desde 30/12/1899, ou 01/01/1904) → Carbon em UTC, sem fuso aplicado. */
    private function serialToDate(float $serial): Carbon
    {
        $base = $this->date1904 ? Carbon::create(1904, 1, 1, 0, 0, 0, 'UTC') : Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC');
        $dias = (int) floor($serial);
        $segundos = (int) round(($serial - $dias) * 86400);

        return $base->addDays($dias)->addSeconds($segundos);
    }

    private function loadWorkbook(): void
    {
        $workbook = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            throw new RuntimeException('O arquivo não tem a estrutura de uma planilha Excel (.xlsx).');
        }

        $this->date1904 = (bool) preg_match('/<workbookPr[^>]*date1904="(1|true)"/', $workbook);

        $alvos = [];

        if (preg_match_all('/<Relationship\b[^>]*>/', $rels, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/Id="([^"]+)"/', $tag, $id) && preg_match('/Target="([^"]+)"/', $tag, $target)) {
                    $caminho = ltrim($target[1], '/');
                    $alvos[$id[1]] = str_starts_with($caminho, 'xl/') ? $caminho : 'xl/'.$caminho;
                }
            }
        }

        if (preg_match_all('/<sheet\b[^>]*>/', $workbook, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/name="([^"]*)"/', $tag, $nome) && preg_match('/r:id="([^"]+)"/', $tag, $rid) && isset($alvos[$rid[1]])) {
                    $this->sheets[html_entity_decode($nome[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = $alvos[$rid[1]];
                }
            }
        }
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return;
        }

        $reader = new XMLReader;
        $reader->XML($xml);

        // readOuterXml() não move o cursor; o read() seguinte desce pelos
        // filhos (<t>, <r>), que não são <si> e são ignorados. Chamar next()
        // aqui pularia o <si> vizinho quando não há espaço em branco entre eles.
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $this->sharedStrings[] = $this->richText($reader->readOuterXml());
            }
        }

        $reader->close();
    }

    /** Concatena os trechos <t> de um <si>/<is> (texto simples ou rich text). */
    private function richText(string $xml): string
    {
        if (! preg_match_all('/<t(?:\s[^>]*)?>(.*?)<\/t>/s', $xml, $partes)) {
            return '';
        }

        return html_entity_decode(implode('', $partes[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function loadStyles(): void
    {
        $xml = $this->zip->getFromName('xl/styles.xml');

        if ($xml === false) {
            return;
        }

        $customDate = [];

        if (preg_match('/<numFmts[^>]*>(.*?)<\/numFmts>/s', $xml, $bloco) && preg_match_all('/<numFmt\b[^>]*>/', $bloco[1], $fmts)) {
            foreach ($fmts[0] as $fmt) {
                if (preg_match('/numFmtId="(\d+)"/', $fmt, $id) && preg_match('/formatCode="([^"]*)"/', $fmt, $code)) {
                    $customDate[(int) $id[1]] = $this->looksLikeDate(html_entity_decode($code[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
                }
            }
        }

        if (! preg_match('/<cellXfs[^>]*>(.*?)<\/cellXfs>/s', $xml, $bloco) || ! preg_match_all('/<xf\b[^>]*\/?>/', $bloco[1], $xfs)) {
            return;
        }

        foreach ($xfs[0] as $indice => $xf) {
            $numFmt = preg_match('/numFmtId="(\d+)"/', $xf, $m) ? (int) $m[1] : 0;
            $this->dateStyles[$indice] = in_array($numFmt, self::BUILTIN_DATE_FORMATS, true) || ($customDate[$numFmt] ?? false);
        }
    }

    /** Um formato é de data quando tem d/m/y/h (fora de colchetes e aspas) e não é científico/percentual. */
    private function looksLikeDate(string $formato): bool
    {
        $limpo = preg_replace(['/\[[^\]]*\]/', '/"[^"]*"/', '/\\\\./'], '', $formato) ?? $formato;

        if (preg_match('/[#0%E]/', $limpo)) {
            return false;
        }

        return preg_match('/[dmyhs]/i', $limpo) === 1;
    }

    private function findSheet(string $nome): ?string
    {
        foreach (array_keys($this->sheets) as $existente) {
            if (mb_strtoupper(trim($existente)) === mb_strtoupper(trim($nome))) {
                return $existente;
            }
        }

        return null;
    }
}
