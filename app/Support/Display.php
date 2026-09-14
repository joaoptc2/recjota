<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Client;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Camada de apresentação de datas (R2 / Seção 8.4).
 *
 * O banco e os jobs operam em UTC. A conversão para o fuso do cliente acontece
 * AQUI e em nenhum outro lugar. Nenhuma data de agendamento é gravada em
 * horário local.
 */
final class Display
{
    public const DATETIME = 'd/m/Y H:i';

    public const DATE = 'd/m/Y';

    public const TIME = 'H:i';

    public static function timezoneFor(Client|User|string|null $context = null): string
    {
        return match (true) {
            is_string($context) && $context !== '' => $context,
            $context instanceof Client => $context->displayTimezone(),
            $context instanceof User => $context->displayTimezone(),
            default => auth()->user()?->displayTimezone() ?? config('agency.default_timezone'),
        };
    }

    public static function carbon(DateTimeInterface|string|null $value, Client|User|string|null $context = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->setTimezone(self::timezoneFor($context));
    }

    public static function datetime(DateTimeInterface|string|null $value, Client|User|string|null $context = null, string $format = self::DATETIME): string
    {
        return self::carbon($value, $context)?->format($format) ?? '—';
    }

    public static function date(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return self::datetime($value, $context, self::DATE);
    }

    public static function time(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return self::datetime($value, $context, self::TIME);
    }

    /**
     * Texto auxiliar do composer: "18:00 no horário de Brasília — 21:00 UTC".
     * Elimina a classe de bug mais comum em sistemas de agendamento.
     */
    public static function localWithUtc(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        $local = self::carbon($value, $context);

        if ($local === null) {
            return '—';
        }

        return sprintf(
            '%s (%s) — %s UTC',
            $local->format(self::DATETIME),
            self::timezoneLabel(self::timezoneFor($context)),
            $local->copy()->utc()->format(self::DATETIME),
        );
    }

    /**
     * Converte uma data digitada no fuso do cliente para o UTC que vai ao banco.
     * Único caminho permitido para gravar scheduled_at.
     */
    public static function toUtc(string $localValue, Client|User|string|null $context = null): Carbon
    {
        return Carbon::parse($localValue, self::timezoneFor($context))->utc();
    }

    public static function timezoneLabel(string $timezone): string
    {
        return match ($timezone) {
            'America/Sao_Paulo' => 'horário de Brasília',
            default => str_replace('_', ' ', $timezone),
        };
    }

    public static function relative(DateTimeInterface|string|null $value, Client|User|string|null $context = null): string
    {
        return self::carbon($value, $context)?->diffForHumans() ?? '—';
    }
}
