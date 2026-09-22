<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Dump SQL em PHP puro (Seção 10): a hospedagem não tem mysqldump nem
 * permite exec()/proc_open(). Percorre as tabelas em blocos de 500 linhas
 * e escreve INSERTs num arquivo gzip, sem carregar a base na memória.
 *
 * MySQL: reproduz `SHOW CREATE TABLE`. SQLite (testes/local): usa o SQL de
 * sqlite_master. Nos dois casos o resultado restaura no mesmo motor.
 */
class DatabaseDumper
{
    public const CHUNK = 500;

    /** Grava o dump e devolve quantas tabelas entraram. */
    public function dumpToFile(string $caminho): int
    {
        $conexao = DB::connection();
        $driver = $conexao->getDriverName();

        $gz = @gzopen($caminho, 'wb6');

        if ($gz === false) {
            throw new RuntimeException(sprintf('Não foi possível criar %s. Confira a permissão de escrita em storage/app/backups.', $caminho));
        }

        try {
            gzwrite($gz, sprintf("-- Recjota: backup de %s (%s)\n-- Gerado em %s UTC\n\n", $conexao->getDatabaseName(), $driver, now()->toDateTimeString()));

            if ($driver === 'mysql') {
                gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
            } elseif ($driver === 'sqlite') {
                gzwrite($gz, "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
            }

            $tabelas = $this->tables($conexao, $driver);

            foreach ($tabelas as $tabela) {
                $this->dumpTable($gz, $conexao, $driver, $tabela);
            }

            if ($driver === 'mysql') {
                gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
            } elseif ($driver === 'sqlite') {
                gzwrite($gz, "COMMIT;\n");
            }

            return count($tabelas);
        } finally {
            gzclose($gz);
        }
    }

    /** @return array<int, string> */
    private function tables(Connection $conexao, string $driver): array
    {
        if ($driver === 'sqlite') {
            return array_map(
                fn (object $l) => (string) $l->name,
                $conexao->select("select name from sqlite_master where type = 'table' and name not like 'sqlite_%' order by name"),
            );
        }

        return array_map(fn (object $l) => (string) array_values((array) $l)[0], $conexao->select('SHOW TABLES'));
    }

    /** @param  resource  $gz */
    private function dumpTable($gz, Connection $conexao, string $driver, string $tabela): void
    {
        $nome = $this->quoteIdentifier($driver, $tabela);

        if ($driver === 'sqlite') {
            $create = $conexao->selectOne("select sql from sqlite_master where type = 'table' and name = ?", [$tabela]);
            gzwrite($gz, sprintf("DROP TABLE IF EXISTS %s;\n%s;\n", $nome, (string) ($create->sql ?? '')));
        } else {
            $create = (array) $conexao->selectOne('SHOW CREATE TABLE '.$nome);
            gzwrite($gz, sprintf("DROP TABLE IF EXISTS %s;\n%s;\n", $nome, (string) ($create['Create Table'] ?? '')));
        }

        $ultimo = null;
        $chave = $this->primaryKey($conexao, $driver, $tabela);

        while (true) {
            $query = $conexao->table($tabela)->limit(self::CHUNK);

            if ($chave !== null) {
                $query->orderBy($chave);

                if ($ultimo !== null) {
                    $query->where($chave, '>', $ultimo);
                }
            } elseif ($ultimo !== null) {
                $query->offset((int) $ultimo);
            }

            $linhas = $query->get();

            if ($linhas->isEmpty()) {
                break;
            }

            $colunas = array_keys((array) $linhas->first());
            $valores = $linhas->map(fn (object $l) => '('.implode(', ', array_map(fn ($v) => $this->quoteValue($conexao, $v), array_values((array) $l))).')');

            gzwrite($gz, sprintf(
                "INSERT INTO %s (%s) VALUES\n%s;\n",
                $nome,
                implode(', ', array_map(fn (string $c) => $this->quoteIdentifier($driver, $c), $colunas)),
                $valores->implode(",\n"),
            ));

            if ($chave !== null) {
                $ultimo = ((array) $linhas->last())[$chave];
            } else {
                $ultimo = (int) $ultimo + $linhas->count();
            }

            if ($linhas->count() < self::CHUNK) {
                break;
            }
        }

        gzwrite($gz, "\n");
    }

    private function primaryKey(Connection $conexao, string $driver, string $tabela): ?string
    {
        if ($driver === 'sqlite') {
            foreach ($conexao->select(sprintf('PRAGMA table_info(%s)', $this->quoteIdentifier($driver, $tabela))) as $coluna) {
                if ((int) $coluna->pk === 1) {
                    return (string) $coluna->name;
                }
            }

            return null;
        }

        $chaves = $conexao->select(sprintf("SHOW KEYS FROM %s WHERE Key_name = 'PRIMARY'", $this->quoteIdentifier($driver, $tabela)));

        return count($chaves) === 1 ? (string) $chaves[0]->Column_name : null;
    }

    private function quoteIdentifier(string $driver, string $nome): string
    {
        return $driver === 'mysql' ? '`'.str_replace('`', '``', $nome).'`' : '"'.str_replace('"', '""', $nome).'"';
    }

    private function quoteValue(Connection $conexao, mixed $valor): string
    {
        if ($valor === null) {
            return 'NULL';
        }

        if (is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if (is_bool($valor)) {
            return $valor ? '1' : '0';
        }

        return $conexao->getPdo()->quote((string) $valor);
    }
}
