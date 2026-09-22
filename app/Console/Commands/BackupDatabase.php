<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Backup\DatabaseDumper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backup diário do banco (Seção 10), sem mysqldump nem exec(): o dump é
 * escrito em PHP puro, comprimido com gzip, em storage/app/backups (fora do
 * webroot). Retenção de 14 dias. O download é pela tela de Configurações.
 */
class BackupDatabase extends Command
{
    public const RETENTION_DAYS = 14;

    protected $signature = 'backup:database {--reter=14 : Dias de retenção dos arquivos antigos}';

    protected $description = 'Gera um dump gzip do banco em storage/app/backups e apaga os mais velhos que a retenção';

    public function handle(DatabaseDumper $dumper): int
    {
        $pasta = storage_path('app/backups');
        File::ensureDirectoryExists($pasta, 0750);

        $arquivo = $pasta.DIRECTORY_SEPARATOR.'banco-'.now()->format('Y-m-d-His').'.sql.gz';

        try {
            $tabelas = $dumper->dumpToFile($arquivo);
        } catch (Throwable $e) {
            @unlink($arquivo);
            Log::error('Backup do banco falhou', ['erro' => $e->getMessage()]);
            $this->error('Backup falhou: '.$e->getMessage());

            return self::FAILURE;
        }

        $tamanho = (int) @filesize($arquivo);
        $this->info(sprintf('Backup gravado: %s (%d tabela(s), %s).', basename($arquivo), $tabelas, $this->humanSize($tamanho)));

        $removidos = $this->prune($pasta, max(1, (int) $this->option('reter')));
        $this->line(sprintf('%d backup(s) antigo(s) removido(s).', $removidos));

        Log::info('Backup do banco concluído', ['arquivo' => basename($arquivo), 'bytes' => $tamanho, 'tabelas' => $tabelas]);

        return self::SUCCESS;
    }

    private function prune(string $pasta, int $dias): int
    {
        $limite = now()->subDays($dias)->timestamp;
        $removidos = 0;

        foreach (File::files($pasta) as $arquivo) {
            if (str_starts_with($arquivo->getFilename(), 'banco-') && $arquivo->getMTime() < $limite && @unlink($arquivo->getPathname())) {
                $removidos++;
            }
        }

        return $removidos;
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576
            ? number_format($bytes / 1048576, 1, ',', '.').' MB'
            : number_format($bytes / 1024, 0, ',', '.').' KB';
    }
}
