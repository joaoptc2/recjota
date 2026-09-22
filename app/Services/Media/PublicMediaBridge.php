<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\MediaAsset;
use App\Models\Scopes\ClientScope;
use App\Services\Media\Contracts\MediaSourceReader;
use App\Support\Exceptions\MediaBridgeFailed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Ponte de mídia pública (Seção 7.4).
 *
 * A Meta só aceita mídia por URL pública com Content-Type correto; link de
 * Drive/OneDrive não serve. Então a mídia é copiada TEMPORARIAMENTE para uma
 * pasta servida pelo Apache (MEDIA_BRIDGE_PATH), com nome imprevisível, e
 * expurgada depois. Regras:
 *
 *  - o nome do arquivo é aleatório (32 hex): ninguém adivinha a URL;
 *  - a extensão sai do MIME REAL (finfo), nunca do nome original — é a
 *    extensão que faz o Apache mandar o Content-Type certo e é a allowlist
 *    do .htaccess que impede qualquer outra coisa de ser servida;
 *  - toda cópia tem prazo (`public_temp_expires_at`) e o cron apaga o que
 *    venceu; a varredura de órfãos apaga o que ficou de um job que morreu;
 *  - nenhuma operação assume que a pasta existe: hospedagem compartilhada
 *    pode ser restaurada de backup sem ela.
 */
class PublicMediaBridge
{
    /** MIME aceito pela Meta → extensão gravada na pasta pública. */
    public const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
    ];

    public function __construct(
        private readonly MediaSourceReader $leitor,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Expõe o asset publicamente e devolve a URL absoluta (HTTPS) da cópia.
     * Se já existe cópia válida (não vencida e com arquivo no disco), ela é
     * reaproveitada — o job de publicação pode ser reexecutado sem duplicar.
     *
     * @throws MediaBridgeFailed
     */
    public function publish(MediaAsset $asset): string
    {
        if ($this->hasValidPublicCopy($asset)) {
            return $this->urlFor((string) $asset->public_temp_path);
        }

        // Sobras de uma cópia vencida ou meio-gravada saem antes da nova.
        if ($asset->public_temp_path !== null) {
            $this->release($asset);
        }

        $origem = $this->leitor->localPath($asset);
        $mime = $this->detectMime($origem);
        $extensao = self::MIME_EXTENSIONS[$mime] ?? null;

        if ($extensao === null) {
            throw MediaBridgeFailed::unsupportedMime($asset, $mime, array_keys(self::MIME_EXTENSIONS));
        }

        $this->ensureBridgeDirectory();

        $relativo = $asset->ulid.'/'.bin2hex(random_bytes(16)).'.'.$extensao;
        $destino = $this->absolutePath($relativo);

        File::ensureDirectoryExists(dirname($destino), 0755);

        if (! @copy($origem, $destino)) {
            throw MediaBridgeFailed::unwritable($destino);
        }

        $asset->forceFill([
            'public_temp_path' => $relativo,
            'public_temp_expires_at' => now()->addHours($this->ttlHours()),
        ])->save();

        return $this->urlFor($relativo);
    }

    /** Apaga a cópia pública (arquivo e pasta do ulid) e limpa as colunas. */
    public function release(MediaAsset $asset): void
    {
        $this->deleteAssetDirectory($asset->ulid);

        if ($asset->public_temp_path !== null || $asset->public_temp_expires_at !== null) {
            $asset->forceFill([
                'public_temp_path' => null,
                'public_temp_expires_at' => null,
            ])->save();
        }
    }

    /** Remove toda cópia cujo prazo venceu. Devolve quantas foram removidas. */
    public function purgeExpired(): int
    {
        return $this->tenant->withoutRestriction(function (): int {
            $total = 0;

            $this->unscopedQuery()
                ->withExpiredPublicCopy()
                ->orderBy('id')
                ->chunkById(100, function ($assets) use (&$total): void {
                    foreach ($assets as $asset) {
                        $this->release($asset);
                        $total++;
                    }
                });

            return $total;
        });
    }

    /**
     * Varre o diretório da ponte e apaga pastas que não pertencem a nenhum
     * asset com cópia pública registrada — lixo de job que morreu entre
     * gravar o arquivo e salvar a coluna, ou de asset apagado à força.
     */
    public function sweepOrphans(): int
    {
        $raiz = $this->root();

        if (! is_dir($raiz)) {
            return 0;
        }

        // MEDIA_BRIDGE_PATH apontando para a raiz do site, do app ou do
        // storage apagaria pastas que não são da ponte. Melhor não varrer.
        $real = realpath($raiz) ?: $raiz;

        foreach ([base_path(), public_path(), storage_path(), storage_path('app')] as $protegido) {
            if ($real === (realpath($protegido) ?: $protegido)) {
                throw MediaBridgeFailed::misconfigured(sprintf('MEDIA_BRIDGE_PATH (%s) é uma pasta do sistema; use uma subpasta dedicada, como public/media-tmp', $raiz));
            }
        }

        // Só pastas com nome de ULID são da ponte; qualquer outra coisa ali
        // (.htaccess, pasta criada à mão) fica como está.
        $pastas = collect(File::directories($raiz))
            ->filter(fn (string $caminho) => self::isUlid(basename($caminho)))
            ->mapWithKeys(fn (string $caminho) => [basename($caminho) => $caminho]);

        if ($pastas->isEmpty()) {
            return 0;
        }

        $validos = $this->tenant->withoutRestriction(fn () => $this->unscopedQuery()
            ->whereNotNull('public_temp_path')
            ->whereIn('ulid', $pastas->keys()->all())
            ->pluck('ulid')
            ->all());

        $removidas = 0;

        foreach ($pastas as $ulid => $caminho) {
            if (in_array($ulid, $validos, true)) {
                continue;
            }

            if (File::deleteDirectory($caminho)) {
                $removidas++;
            }
        }

        return $removidas;
    }

    /** Diretório da ponte, criado (com .htaccess) na primeira vez que é preciso. */
    public function ensureBridgeDirectory(): string
    {
        $raiz = $this->root();

        if (! is_dir($raiz) && ! @mkdir($raiz, 0755, true) && ! is_dir($raiz)) {
            throw MediaBridgeFailed::unwritable($raiz);
        }

        // Na hospedagem, MEDIA_BRIDGE_PATH pode apontar para fora de public/,
        // onde o .htaccess versionado não existe. Sem ele a pasta ficaria
        // listável e executável.
        $htaccess = $raiz.DIRECTORY_SEPARATOR.'.htaccess';

        if (! is_file($htaccess)) {
            File::put($htaccess, $this->htaccessContents());
        }

        return $raiz;
    }

    /** Caminho absoluto da cópia pública de um asset (ou null se não há). */
    public function absolutePathFor(MediaAsset $asset): ?string
    {
        return $asset->public_temp_path === null ? null : $this->absolutePath($asset->public_temp_path);
    }

    /** MIME real, lido do conteúdo. Ponto único para trocar a detecção. */
    protected function detectMime(string $caminhoAbsoluto): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = @finfo_file($finfo, $caminhoAbsoluto);
        finfo_close($finfo);

        return $mime !== false ? $mime : null;
    }

    private function hasValidPublicCopy(MediaAsset $asset): bool
    {
        if ($asset->public_temp_path === null || $asset->public_temp_expires_at === null) {
            return false;
        }

        if ($asset->public_temp_expires_at->lessThanOrEqualTo(now())) {
            return false;
        }

        return is_file($this->absolutePath($asset->public_temp_path));
    }

    /** ULID: 26 caracteres Crockford base32, sem I, L, O nem U. */
    public static function isUlid(string $valor): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $valor) === 1;
    }

    private function deleteAssetDirectory(string $ulid): void
    {
        if (! self::isUlid($ulid)) {
            return;
        }

        $pasta = $this->root().DIRECTORY_SEPARATOR.$ulid;

        if (is_dir($pasta)) {
            File::deleteDirectory($pasta);
        }
    }

    /** Query fora do escopo de tenant e incluindo soft-deleted: cópia pública não pode sobreviver ao asset. */
    private function unscopedQuery(): Builder
    {
        return MediaAsset::query()
            ->withoutGlobalScope(ClientScope::class)
            ->withTrashed();
    }

    private function root(): string
    {
        $raiz = rtrim((string) config('agency.media_bridge.path'), '/\\');

        if ($raiz === '') {
            throw MediaBridgeFailed::misconfigured('MEDIA_BRIDGE_PATH está vazio');
        }

        return $raiz;
    }

    private function absolutePath(string $relativo): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativo, '/'));
    }

    /**
     * URL pública da cópia. A Meta só baixa por HTTPS, então um MEDIA_BRIDGE_URL
     * em http:// é reescrito — em produção o site já está atrás de TLS.
     */
    private function urlFor(string $relativo): string
    {
        $base = rtrim((string) config('agency.media_bridge.url'), '/');

        if ($base === '') {
            throw MediaBridgeFailed::misconfigured('MEDIA_BRIDGE_URL está vazio');
        }

        if (Str::startsWith($base, 'http://')) {
            $base = 'https://'.substr($base, strlen('http://'));
        }

        return $base.'/'.ltrim($relativo, '/');
    }

    private function ttlHours(): int
    {
        return max(1, (int) config('agency.media_bridge.ttl_hours', 6));
    }

    private function htaccessContents(): string
    {
        $versionado = public_path('media-tmp/.htaccess');

        if (is_file($versionado)) {
            return (string) file_get_contents($versionado);
        }

        // Cópia mínima do arquivo versionado, para o caso de o deploy ter
        // perdido public/media-tmp.
        return <<<'HTACCESS'
        # Ponte de mídia pública (Seção 7.4): cópias temporárias, nada executável.
        Options -Indexes
        <FilesMatch "\.(php|phtml|php[0-9]|phar|cgi|pl|py|sh|htaccess)$">
            Require all denied
        </FilesMatch>
        <FilesMatch "^.*\.(jpg|jpeg|png|webp|gif|mp4|mov|m4v)$">
            Require all granted
        </FilesMatch>
        <IfModule mod_headers.c>
            Header set X-Robots-Tag "noindex, nofollow, noarchive"
            Header set Cache-Control "private, max-age=600"
        </IfModule>
        <FilesMatch "^(?!.*\.(jpg|jpeg|png|webp|gif|mp4|mov|m4v)$).*$">
            Require all denied
        </FilesMatch>
        HTACCESS;
    }
}
