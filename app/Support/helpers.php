<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\User;
use App\Support\Display;
use App\Support\Tenancy\TenantContext;

if (! function_exists('display_datetime')) {
    /**
     * Helper único de conversão UTC → fuso de exibição (Seção 8.4).
     * Nenhum Blade deve chamar ->format() direto em um datetime do banco.
     */
    function display_datetime(DateTimeInterface|string|null $value, Client|User|string|null $context = null, string $format = Display::DATETIME): string
    {
        return Display::datetime($value, $context, $format);
    }
}

if (! function_exists('display_date')) {
    function display_date(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return Display::date($value, $context);
    }
}

if (! function_exists('display_time')) {
    function display_time(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return Display::time($value, $context);
    }
}

if (! function_exists('display_local_with_utc')) {
    function display_local_with_utc(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return Display::localWithUtc($value, $context);
    }
}

if (! function_exists('tenant')) {
    function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
