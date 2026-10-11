<?php

namespace App\Services\Interfaces;

use App\Models\ChannelConnection;

/**
 * v7.37 – OTA / channel-manager driver contract.
 *
 * Every channel transport (the built-in hub protocol, the simulator, a mirror
 * feed, and later real OTA adapters such as Booking.com EPS or Expedia EPS)
 * implements this interface. ChannelService owns dirty-tracking, ARI pricing,
 * booking application and the push log; the driver only talks to the channel.
 *
 * See docs/CHANNEL-ADAPTER.md for the adapter spec and test matrix.
 */
interface ChannelDriver
{
    /** Stable id (matches ChannelConnection::$provider). */
    public function id(): string;

    /** Human label for the UI. */
    public function label(): string;

    /**
     * Probe the channel endpoint without changing any state.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(ChannelConnection $c): array;

    /**
     * Deliver ARI chunks (each chunk = [room_type_id => [date => ari-row]]).
     * Call $ack($chunk) exactly once per chunk the channel has accepted so the
     * PMS can acknowledge those room-nights. Throws on failure.
     *
     * @param  callable  $ack  fn (array $chunk): void
     * @return array{requests: int, http: ?int}
     */
    public function pushAri(ChannelConnection $c, array $chunks, string $kind, callable $ack): array;

    /**
     * Pull bookings created/updated at the channel since $since (raw arrays —
     * ChannelService applies them through the mapping pipeline).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pullBookings(ChannelConnection $c, ?\DateTimeInterface $since): array;

    /**
     * Cancel a booking at the channel (e.g. after a no-show release).
     *
     * @return array{ok: bool, message: string}
     */
    public function cancelBooking(ChannelConnection $c, string $externalRef): array;
}
