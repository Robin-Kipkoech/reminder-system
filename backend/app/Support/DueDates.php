<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class DueDates
{
    /**
     * Today in the application timezone (Africa/Nairobi), at midnight.
     */
    public static function today(): Carbon
    {
        return Carbon::today();
    }

    /**
     * Signed day difference from today: negative when the date has passed.
     */
    public static function daysUntil(mixed $date): int
    {
        if ($date === null || $date === '') {
            return 0;
        }

        return (int) self::today()->diffInDays(Carbon::parse($date)->startOfDay(), false);
    }

    public static function isPast(mixed $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        return Carbon::parse($date)->startOfDay()->lessThan(self::today());
    }

    public static function toDateString(mixed $date): string
    {
        return Carbon::parse($date)->toDateString();
    }
}
