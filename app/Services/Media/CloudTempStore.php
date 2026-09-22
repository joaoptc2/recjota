<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Support\Exceptions\MediaBridgeFailed;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Pasta temporária, fora do webroot, para originais baixados da nuvem
 * (import e ponte de mídia). Nada aqui é servido nem dura: o cron horário
 * apaga o que passou do prazo; quem baixou apaga o que terminou de usar.
 */
class CloudTempStore
{
    /** Caminho absoluto para um download novo; a pasta é criada se preciso. */
    public function pathFor(string $chave, string $nomeOriginal): string
    {
        $raiz = $this->root();

        if (! is_dir($raiz) && ! @mkdir($raiz, 0750, true) && ! is_dir($raiz)) {
            throw MediaBridgeFailed::unwritable($raiz);
        }

        $extensao = Str::lower(pathinfo($nomeOriginal, PATHINFO_EXTENSION));
        $extensao = preg_match('/^[a-z0-9]{1,5}$/', $extensao) === 1 ? $extensao : 'bin';

        return $raiz.DIRECTORY_SEPARATOR.preg_replace('/[^A-Za-z0-9_-]/', '', $chave).'.'.$extensao;
    }

    /** Já existe um download recente para esta chave? Devolve o caminho. */
    public function existing(string $chave): ?string
    {
        $raiz = $this->root();

        if (! is_dir($raiz)) {
            return null;
        }

        $prefixo = preg_replace('/[^A-Za-z0-9_-]/', '', $chave).'.';

        foreach (File::files($raiz) as $arquivo) {
            if (str_starts_with($arquivo->getFilename(), $prefixo) && $arquivo->getMTime() > time() - $this->ttlSeconds()) {
                return $arquivo->getPathname();
            }
        }

        return null;
    }

    public function forget(?string $caminho): void
    {
        if ($caminho !== null && is_file($caminho) && str_starts_with(realpath($caminho) ?: $caminho, realpath($this->root()) ?: $this->root())) {
            @unlink($caminho);
        }
    }

    /** Remove o que passou do prazo. Devolve quantos arquivos saíram. */
    public function purgeExpired(): int
    {
        $raiz = $this->root();

        if (! is_dir($raiz)) {
            return 0;
        }

        $limite = time() - $this->ttlSeconds();
        $removidos = 0;

        foreach (File::files($raiz) as $arquivo) {
            if ($arquivo->getMTime() <= $limite && @unlink($arquivo->getPathname())) {
                $removidos++;
            }
        }

        return $removidos;
    }

    public function root(): string
    {
        $raiz = rtrim((string) config('agency.cloud_temp.path'), '/\\');

        if ($raiz === '') {
            throw MediaBridgeFailed::misconfigured('CLOUD_TEMP_PATH está vazio');
        }

        return $raiz;
    }

    private function ttlSeconds(): int
    {
        return max(1, (int) config('agency.cloud_temp.ttl_hours', 2)) * 3600;
    }
}
