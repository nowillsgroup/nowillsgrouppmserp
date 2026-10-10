<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LaundryOrder extends Model
{
    use BelongsToHotel;

    public const STATUSES = ['collected' => 'Collected', 'processing' => 'Processing', 'ready' => 'Ready', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];

    public const OPEN = ['collected', 'processing', 'ready'];

    public const TONES = ['collected' => 'blue', 'processing' => 'amber', 'ready' => 'purple', 'delivered' => 'green', 'cancelled' => 'slate'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['subtotal' => 'decimal:2', 'express_charge' => 'decimal:2', 'total' => 'decimal:2', 'collected_at' => 'datetime', 'promised_at' => 'datetime',
            'ready_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(LaundryOrderLine::class)->orderBy('id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
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

    public function guestRequest()
    {
        return $this->belongsTo(GuestRequest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deliverer()
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->promised_at && $this->promised_at->isPast();
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    /** Where the order came from / goes to, for lists. */
    public function whoLabel(): string
    {
        return $this->type === 'walk_in' ? $this->guest_name.' (walk-in)' : 'Room '.($this->room?->room_no ?? '—').' · '.$this->guest_name;
    }
}
