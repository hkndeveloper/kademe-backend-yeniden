<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class IstanbulDateTime
{
    public const TIMEZONE = 'Europe/Istanbul';

    public static function toUtc(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, self::TIMEZONE)->utc();
    }

    public static function toUtcIso(mixed $value): ?string
    {
        return self::toUtc($value)?->toIso8601String();
    }

    public static function normalizeFields(array $payload, array $fields): array
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = self::toUtc($payload[$field]);
            }
        }

        return $payload;
    }

    public static function format(mixed $value, string $format = 'd.m.Y H:i'): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return Carbon::parse($value)->setTimezone(self::TIMEZONE)->format($format);
    }
}