<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.3 a guest transfer: airport / station pick-up, drop or local transfer. */
class TransportTrip extends Model
{
    use BelongsToHotel;

    public const TYPES = ['pickup' => 'Pick-up', 'drop' => 'Drop', 'transfer' => 'Transfer / local'];

    public const STATUSES = ['requested' => 'Requested', 'confirmed' => 'Confirmed', 'assigned' => 'Assigned', 'en_route' => 'En route', 'onboard' => 'Guest on board',
        'completed' => 'Completed', 'cancelled' => 'Cancelled', 'no_show' => 'No-show'];

    public const TONES = ['requested' => 'amber', 'confirmed' => 'blue', 'assigned' => 'purple', 'en_route' => 'green', 'onboard' => 'green', 'completed' => 'slate', 'cancelled' => 'red', 'no_show' => 'red'];

    /** not finished yet */
    public const OPEN = ['requested', 'confirmed', 'assigned', 'en_route', 'onboard'];

    /** not yet on the road – can still be edited, re-assigned, cancelled */
    public const PLANNED = ['requested', 'confirmed', 'assigned'];

    /** vehicle out with the trip */
    public const ACTIVE = ['en_route', 'onboard'];

    public const BILLING = ['room' => 'Post to room bill', 'direct' => 'Guest pays the driver / desk', 'complimentary' => 'Complimentary'];

    public const SOURCES = ['staff' => 'Desk', 'reservation' => 'Reservation', 'portal' => 'Guest portal', 'request' => 'Guest request'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'busy_from' => 'datetime', 'busy_to' => 'datetime', 'amount' => 'decimal:2', 'extra_amount' => 'decimal:2', 'total' => 'decimal:2',
            'assigned_at' => 'datetime', 'started_at' => 'datetime', 'onboard_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'guest_notified_at' => 'datetime', 'driver_notified_at' => 'datetime', 'reminder_sent_at' => 'datetime'];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function guestRequest()
    {
        return $this->belongsTo(GuestRequest::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver()
    {
        return $this->belongsTo(Driver::class);
    }

    public function tariff()
    {
        return $this->belongsTo(TransportTariff::class, 'transport_tariff_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isPlanned(): bool
    {
        return in_array($this->status, self::PLANNED, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    /** Room-billed and not on a bill yet. */
    public function unbilled(): bool
    {
        return $this->billing === 'room' && ! $this->folio_transaction_id && (float) $this->total > 0 && ! in_array($this->status, ['cancelled', 'no_show'], true);
    }

    public function km(): ?int
    {
        return $this->odo_start !== null && $this->odo_end !== null ? max(0, $this->odo_end - $this->odo_start) : null;
    }

    public function route(): string
    {
        return $this->pickup_point.' → '.$this->dropoff_point;
    }

    /** Name board text shown to the driver: title + surname in capitals. */
    public function nameBoard(): string
    {
        return mb_strtoupper(trim($this->passenger_name));
    }

    /** Minutes late leaving (en route after busy_from) – null when not started. */
    public function lateMinutes(): ?int
    {
        return $this->started_at ? max(0, (int) $this->busy_from->diffInMinutes($this->started_at, false)) : null;
    }
}
