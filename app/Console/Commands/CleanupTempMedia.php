<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Media\PublicMediaBridge;
use Illuminate\Console\Command;

/**
 * Expurgo da ponte de mídia pública (Seção 7.4).
 *
 * De hora em hora remove as cópias cujo prazo venceu. Com `--orfaos` também
 * varre o diretório atrás de pastas sem asset correspondente — essa varredura
 * lista o disco inteiro da ponte, então roda uma vez por dia (05:00 UTC, ver
 * routes/console.php), e não a cada hora.
 */
class CleanupTempMedia extends Command
{
    protected $signature = 'media:cleanup-temp {--orfaos : Também remove pastas órfãs, sem asset correspondente}';

    protected $description = 'Remove da ponte pública as cópias de mídia vencidas (e, com --orfaos, as órfãs)';

    public function handle(PublicMediaBridge $ponte): int
    {
        $vencidas = $ponte->purgeExpired();
        $this->info(sprintf('%d cópia(s) vencida(s) removida(s).', $vencidas));

        if ($this->option('orfaos')) {
            $orfas = $ponte->sweepOrphans();
            $this->info(sprintf('%d pasta(s) órfã(s) removida(s).', $orfas));
        }

        return self::SUCCESS;
    }
}
