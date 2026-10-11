<?php

namespace App\Services\Interfaces\Drivers;

use App\Models\ChannelConnection;
use App\Services\Interfaces\ChannelDriver;

/**
 * v7.37 – built-in test channel. Accepts every ARI push locally (states are
 * acknowledged like a real channel), always passes the connection test, and
 * returns a small deterministic booking fixture on pull — handy for demos and
 * for exercising the two-way pipeline without an OTA account.
 */
class SimulatorChannelDriver implements ChannelDriver
{
    /** Bookings returned by pullBookings() — demo fixture. */
    public const FIXTURE = [
        ['ref' => 'SIM-1001', 'guest' => 'Ama Boateng', 'room_type' => 'STD', 'arrival' => '+7 days', 'departure' => '+9 days', 'nights' => 2, 'rate' => 950.00, 'source' => 'simulator'],
        ['ref' => 'SIM-1002', 'guest' => 'Kwesi Appiah', 'room_type' => 'DLX', 'arrival' => '+10 days', 'departure' => '+11 days', 'nights' => 1, 'rate' => 1700.00, 'source' => 'simulator'],
    ];

    public function id(): string
    {
        return 'simulator';
    }

    public function label(): string
    {
        return 'Built-in test channel (simulator)';
    }

    public function test(ChannelConnection $c): array
    {
        return ['ok' => true, 'message' => 'Simulated channel ready – pushes are acknowledged locally; pull returns the demo fixture.'];
    }

    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array
    {
        foreach ($chunks as $chunk) {
            $ack($chunk);
        }

        return ['requests' => count($chunks), 'http' => 200];
    }

    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array
    {
        $out = [];
        foreach (self::FIXTURE as $row) {
            $row['arrival'] = bdate()->modify($row['arrival'])->toDateString();
            $row['departure'] = bdate()->modify($row['departure'])->toDateString();
            $out[] = $row;
        }

        return $out;
    }

    public function cancelBooking(ChannelConnection $c, string $externalRef): array
    {
        return ['ok' => true, 'message' => "Simulator cancelled {$externalRef}."];
    }
}
