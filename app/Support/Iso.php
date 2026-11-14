<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Formats instants for JSON: UTC, ISO-8601, microseconds, e.g. 2026-11-14T06:41:23.008123Z */
final class Iso
{
    public static function format(?CarbonInterface $date): ?string
    {
        return $date?->copy()->utc()->format('Y-m-d\TH:i:s.u\Z');
    }

    public static function now(): Carbon
    {
        return Carbon::now('UTC');
    }

    /** Value for query-builder writes to DATETIME(6) columns. */
    public static function db(?CarbonInterface $date = null): string
    {
        return ($date ?? self::now())->copy()->utc()->format('Y-m-d H:i:s.u');
    }
}
