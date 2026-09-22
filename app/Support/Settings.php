<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Configurações da agência (Seção 11.2): linhas da tabela `settings` com
 * client_id nulo, lidas de uma vez e mantidas em cache.
 *
 * O .env continua sendo o padrão (config/agency.php); o que for salvo aqui
 * sobrescreve sem exigir FTP. Só chaves conhecidas são aceitas — nada de
 * virar um saco genérico de valores.
 */
final class Settings
{
    public const CACHE_KEY = 'settings:agency';

    public const CACHE_TTL_SECONDS = 3600;

    /** Chave da tabela → chave de config que ela sobrescreve. */
    public const KEYS = [
        'agency.name' => 'agency.name',
        'agency.support_email' => 'agency.support_email',
        'agency.primary_color' => 'agency.primary_color',
    ];

    /** @var array<string, mixed>|null */
    private ?array $loaded = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->assertKnown($key);

        $valores = $this->all();

        if (array_key_exists($key, $valores) && $valores[$key] !== null && $valores[$key] !== '') {
            return $valores[$key];
        }

        return $default ?? config(self::KEYS[$key]);
    }

    public function set(string $key, mixed $value): void
    {
        $this->assertKnown($key);

        Setting::query()->updateOrCreate(
            ['key' => $key, 'client_id' => null],
            ['value' => $value],
        );

        $this->flush();
        $this->applyToConfig();
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->loaded ??= Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Setting::query()
                ->whereNull('client_id')
                ->whereIn('key', array_keys(self::KEYS))
                ->pluck('value', 'key')
                ->all();
        });
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Sobrescreve config('agency.*') com o que está no banco, para que layout,
     * e-mails e relatórios usem o nome/cor salvos sem cada tela consultar aqui.
     */
    public function applyToConfig(): void
    {
        foreach ($this->all() as $chave => $valor) {
            if (isset(self::KEYS[$chave]) && $valor !== null && $valor !== '') {
                config([self::KEYS[$chave] => $valor]);
            }
        }
    }

    private function assertKnown(string $key): void
    {
        if (! isset(self::KEYS[$key])) {
            throw new \InvalidArgumentException(sprintf('Configuração desconhecida: %s', $key));
        }
    }
}
