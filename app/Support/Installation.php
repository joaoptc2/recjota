<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Estado da instalação.
 *
 * Em hospedagem compartilhada sem SSH, `php artisan migrate` não é uma opção.
 * O instalador web faz esse trabalho — e esta classe é o portão que garante
 * que ele só existe enquanto o sistema NÃO está instalado.
 *
 * São duas travas independentes, de propósito: o arquivo de lock e a presença
 * de usuários no banco. Apagar o lock por acidente não reabre o instalador.
 */
final class Installation
{
    private static ?bool $installed = null;

    public static function lockPath(): string
    {
        return storage_path('app/installed.lock');
    }

    public static function isInstalled(): bool
    {
        return self::$installed ??= file_exists(self::lockPath());
    }

    /** O instalador só aparece se nenhuma das duas travas estiver acionada. */
    public static function isAvailable(): bool
    {
        if (self::isInstalled()) {
            return false;
        }

        try {
            return ! (Schema::hasTable('users') && DB::table('users')->limit(1)->exists());
        } catch (Throwable) {
            /*
             * Banco fora do ar. Falha FECHADA: se o .env já aponta para um
             * banco, presumimos um sistema instalado com o banco temporariamente
             * inacessível, e mantemos o instalador trancado. Abri-lo aqui
             * deixaria qualquer visitante reescrever as credenciais durante uma
             * queda do MySQL.
             */
            return ! self::databaseIsConfigured();
        }
    }

    /** O .env já aponta para algum banco concreto? */
    public static function databaseIsConfigured(): bool
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        return $database !== '' && ! str_contains($database, 'uXXXXXXXX');
    }

    /** Sonda o banco sem explodir quando ele ainda não está configurado. */
    public static function hasUsers(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->limit(1)->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function databaseIsReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return Schema::hasTable('users') && Schema::hasTable('clients');
        } catch (Throwable) {
            return false;
        }
    }

    public static function markInstalled(): void
    {
        @mkdir(dirname(self::lockPath()), 0o775, true);

        file_put_contents(self::lockPath(), sprintf(
            "Instalado em %s (UTC).\nApagar este arquivo NÃO reabre o instalador enquanto houver usuários no banco.\n",
            now()->toIso8601String(),
        ));

        self::$installed = true;
    }

    /**
     * Usado apenas em teste: fixa (true/false) ou descarta (null) a memória do
     * estado de instalação, para não depender do arquivo de lock em disco.
     */
    public static function fake(?bool $installed): void
    {
        self::$installed = $installed;
    }

    /** Usado apenas em teste. */
    public static function forgetCache(): void
    {
        self::$installed = null;
    }
}
