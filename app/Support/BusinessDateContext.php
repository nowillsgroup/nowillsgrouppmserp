<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/** A request-scoped business-date override for replaying offline desk work. */
final class BusinessDateContext
{
    private static ?Carbon $override = null;

    public static function current(): ?Carbon
    {
        return self::$override?->copy();
    }

    public static function run(Carbon $date, callable $callback): mixed
    {
        $previous = self::$override;
        self::$override = $date->copy()->timezone(config('app.timezone'))->startOfDay();
        try {
            return $callback();
        } finally {
            self::$override = $previous;
        }
    }
}
