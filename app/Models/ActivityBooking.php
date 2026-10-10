<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * v5.9 A guest's activity booking, sold by the hotel as agent. Collection "folio": the gross goes on the room bill and is owed to
 * the vendor less the hotel's commission. Collection "vendor": the guest pays the vendor, who owes the hotel its commission.
 */
class ActivityBooking extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['requested' => 'Awaiting vendor', 'waitlisted' => 'Waitlisted', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'no_show' => 'No-show', 'cancelled' => 'Cancelled'];

    public const TONES = ['requested' => 'amber', 'waitlisted' => 'purple', 'confirmed' => 'blue', 'completed' => 'green', 'no_show' => 'red', 'cancelled' => 'slate'];

    /** statuses that hold seats on a departure */
    public const HOLDING = ['requested', 'confirmed', 'completed', 'no_show'];

    /** statuses that can still change */
    public const OPEN = ['requested', 'waitlisted', 'confirmed'];

    public const SOURCES = ['desk' => 'Concierge desk', 'portal' => 'Guest portal', 'phone' => 'Phone / e-mail'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'recognised_on' => 'date', 'price_adult' => 'decimal:2', 'price_child' => 'decimal:2', 'gross' => 'decimal:2', 'commission_pct' => 'decimal:2',
            'commission' => 'decimal:2', 'vendor_net' => 'decimal:2', 'cancel_fee' => 'decimal:2', 'confirmed_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'guest_notified_at' => 'datetime'];
    }

    public function activity()
    {
        return $this->belongsTo(Activity::class);
    }

    public function vendor()
    {
        return $this->belongsTo(ActivityVendor::class, 'activity_vendor_id');
    }

    public function departure()
    {
        return $this->belongsTo(ActivityDeparture::class, 'activity_departure_id');
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

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function settlement()
    {
        return $this->belongsTo(ActivitySettlement::class, 'activity_settlement_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer()
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function startsAt(): Carbon
    {
        return Carbon::parse($this->date->toDateString().' '.$this->time);
    }

    public function pax(): int
    {
        return (int) $this->adults + (int) $this->children;
    }

    public function paxLabel(): string
    {
        return $this->adults.' adult'.($this->adults == 1 ? '' : 's').($this->children ? ' + '.$this->children.' child'.($this->children == 1 ? '' : 'ren') : '');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function collectionLabel(): string
    {
        return $this->collection === 'folio' ? 'Room bill' : 'Pay the vendor';
    }

    /** what the guest is actually charged (the full price, a late-cancellation fee, or nothing) */
    public function charged(): float
    {
        return $this->status === 'cancelled' ? (float) $this->cancel_fee : ((in_array($this->status, ['confirmed', 'completed', 'no_show'], true)) ? (float) $this->gross : 0.0);
    }

    /** latest moment the guest can cancel without a fee */
    public function freeCancelUntil(): Carbon
    {
        return $this->startsAt()->subHours((int) ($this->activity?->free_cancel_hours ?? 24));
    }
}
