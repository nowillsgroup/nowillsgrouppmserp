<?php

namespace App\Support;

use App\Models\Hotel;

/**
 * Holds the hotel (tenant) resolved for the current request.
 * All tenant-owned models are automatically scoped to this hotel.
 */
class Tenant
{
    protected static ?Hotel $hotel = null;

    public static function set(?Hotel $hotel): void
    {
        static::$hotel = $hotel;
        // Timestamps are stored in the property's local time, so console work (seeders, scheduled
        // night audit, iCal sync) must use the same clock as web requests.
        if ($hotel?->timezone && $hotel->timezone !== date_default_timezone_get()) {
            config(['app.timezone' => $hotel->timezone]);
            date_default_timezone_set($hotel->timezone);
        }
    }

    public static function hotel(): ?Hotel
    {
        return static::$hotel;
    }

    public static function id(): ?int
    {
        return static::$hotel?->id;
    }

    public static function check(): bool
    {
        return static::$hotel !== null;
    }
}
