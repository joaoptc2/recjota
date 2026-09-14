<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Edição cirúrgica do .env.
 *
 * Sem SSH não há `php artisan key:generate` nem editor de texto: o instalador
 * precisa gravar as credenciais direto no arquivo, preservando comentários e
 * ordem das chaves que já existem.
 */
final class EnvFile
{
    public function __construct(private readonly string $path) {}

    public static function default(): self
    {
        return new self(app()->environmentFilePath());
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function isWritable(): bool
    {
        return $this->exists() ? is_writable($this->path) : is_writable(dirname($this->path));
    }

    /** @param array<string, string|int|bool|null> $values */
    public function set(array $values): void
    {
        if (! $this->isWritable()) {
            throw new RuntimeException('O arquivo .env não é gravável. Ajuste a permissão para 644 pelo Gerenciador de Arquivos.');
        }

        $contents = $this->exists() ? (string) file_get_contents($this->path) : '';

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->format($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            $contents = preg_match($pattern, $contents) === 1
                ? preg_replace($pattern, $line, $contents, 1)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        $written = file_put_contents($this->path, $contents, LOCK_EX);

        if ($written === false) {
            throw new RuntimeException('Não foi possível gravar o arquivo .env.');
        }
    }

    public function get(string $key): ?string
    {
        if (! $this->exists()) {
            return null;
        }

        $contents = (string) file_get_contents($this->path);

        if (preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim(trim($matches[1]), '"');
    }

    private function format(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        // Aspas só quando necessário, para o arquivo continuar legível.
        return preg_match('/[\s#"\'$]/', $value) === 1
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"'
            : $value;
    }
}
