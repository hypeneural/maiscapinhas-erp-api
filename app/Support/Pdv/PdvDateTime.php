<?php

declare(strict_types=1);

namespace App\Support\Pdv;

use Carbon\CarbonImmutable;
use Throwable;

final class PdvDateTime
{
    public static function parseToUtc(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        try {
            if (self::hasExplicitTimezone($raw)) {
                return CarbonImmutable::parse($raw)->utc();
            }

            $timezone = (string) config('pdv.naive_datetime_timezone', 'America/Sao_Paulo');

            return CarbonImmutable::parse($raw, $timezone)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function hasExplicitTimezone(string $value): bool
    {
        return (bool) preg_match('/(Z|[+\-]\d{2}:\d{2})$/', $value);
    }
}
