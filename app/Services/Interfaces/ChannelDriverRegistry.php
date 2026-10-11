<?php

namespace App\Services\Interfaces;

use App\Models\ChannelConnection;
use App\Services\Interfaces\Drivers\HubChannelDriver;
use App\Services\Interfaces\Drivers\MirrorChannelDriver;
use App\Services\Interfaces\Drivers\SimulatorChannelDriver;
use RuntimeException;

/**
 * v7.37 – channel driver registry. Maps ChannelConnection::$provider to a
 * ChannelDriver implementation. Vendors register adapters here (or from a
 * package service provider) without touching ChannelService.
 */
class ChannelDriverRegistry
{
    /** @var array<string, class-string<ChannelDriver>> */
    protected static array $drivers = [
        'hub' => HubChannelDriver::class,
        'simulator' => SimulatorChannelDriver::class,
        'mirror' => MirrorChannelDriver::class,
        'booking' => \App\Services\Interfaces\Drivers\BookingEpsChannelDriver::class,
    ];

    /** @param  class-string<ChannelDriver>  $class */
    public static function register(string $id, string $class): void
    {
        static::$drivers[$id] = $class;
    }

    public static function for(ChannelConnection $c): ChannelDriver
    {
        $id = (string) $c->provider;
        $class = static::$drivers[$id] ?? null;
        if (! $class || ! is_subclass_of($class, ChannelDriver::class)) {
            throw new RuntimeException("No channel driver registered for provider '{$id}'.");
        }

        return new $class();
    }

    /** id => label, for forms. */
    public static function options(): array
    {
        $out = [];
        foreach (static::$drivers as $id => $class) {
            $out[$id] = (new $class())->label();
        }

        return $out;
    }
}
