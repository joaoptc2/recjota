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

            /*
             * preg_replace_callback e não preg_replace: senhas com $ ou barra
             * invertida seriam interpretadas como referência de captura e
             * gravadas erradas — o instalador validaria a credencial certa e
             * escreveria outra no arquivo.
             */
            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1)
                : rtrim($contents, "\r\n")."\n".$line."\n";
        }

        if (file_put_contents($this->path, $contents, LOCK_EX) === false) {
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

        // O \r cobre .env salvo com quebra de linha do Windows.
        $bruto = trim($matches[1], " \t\r\n");

        if (strlen($bruto) >= 2 && str_starts_with($bruto, "'") && str_ends_with($bruto, "'")) {
            return substr($bruto, 1, -1);
        }

        if (strlen($bruto) >= 2 && str_starts_with($bruto, '"') && str_ends_with($bruto, '"')) {
            return str_replace(['\\"', '\\\\'], ['"', '\\'], substr($bruto, 1, -1));
        }

        return $bruto;
    }

    private function format(string|int|bool|null $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        $value = (string) $value;

        // Aspas só quando necessário, para o arquivo continuar legível.
        return preg_match('/[\s#"\'$\\\\]/', $value) === 1
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"'
            : $value;
    }
}
