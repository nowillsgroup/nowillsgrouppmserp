<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.2 One guest's visit to a kiosk (a check-in, a check-out or a walk-in booking) with its step-by-step trail. */
class KioskSession extends Model
{
    use BelongsToHotel;

    public const KINDS = ['checkin' => 'Check-in', 'checkout' => 'Check-out', 'walkin' => 'Walk-in booking'];

    public const STATUSES = ['active' => 'In progress', 'completed' => 'Completed', 'assist' => 'Needs the desk', 'abandoned' => 'Abandoned', 'cancelled' => 'Cancelled'];

    public const STATUS_TONES = ['active' => 'blue', 'completed' => 'green', 'assist' => 'red', 'abandoned' => 'slate', 'cancelled' => 'slate'];

    public const STEPS = ['find' => 'Find booking', 'details' => 'Guest details', 'document' => 'ID photo', 'sign' => 'Registration card', 'pay' => 'Payment',
        'room' => 'Room', 'keys' => 'Key cards', 'bill' => 'Bill review', 'rooms' => 'Choose room type', 'guest' => 'Guest details', 'feedback' => 'Feedback', 'done' => 'Finished'];

    public const ASSIST_REASONS = ['guest' => 'Guest asked for help', 'not_found' => 'Booking not found', 'not_eligible' => 'Cannot self check-in', 'vip' => 'VIP – personal welcome',
        'id' => 'ID problem', 'payment' => 'Payment problem', 'pay_desk' => 'Wants to pay at the desk', 'no_room' => 'No ready room', 'keys' => 'Key cards not encoded',
        'bill' => 'Bill query / refund', 'checkout' => 'Check-out blocked', 'multi' => 'Several rooms on the booking', 'error' => 'System error'];

    protected $guarded = ['id', 'hotel_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['trail' => 'array', 'details_ok' => 'boolean', 'id_captured' => 'boolean', 'signed' => 'boolean', 'amount_due' => 'decimal:2', 'amount_paid' => 'decimal:2',
            'started_at' => 'datetime', 'last_activity_at' => 'datetime', 'completed_at' => 'datetime', 'assist_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function kiosk()
    {
        return $this->belongsTo(Kiosk::class);
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

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function payment()
    {
        return $this->belongsTo(OnlinePayment::class, 'online_payment_id');
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst($this->kind);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function stepLabel(): string
    {
        return self::STEPS[$this->step] ?? ucfirst($this->step);
    }

    public function assistLabel(): ?string
    {
        return $this->assist_reason ? (self::ASSIST_REASONS[$this->assist_reason] ?? $this->assist_reason) : null;
    }

    /** seconds from start to finish (or to now while running) */
    public function seconds(): int
    {
        return (int) max(0, $this->started_at->diffInSeconds($this->completed_at ?? $this->last_activity_at ?? now()));
    }

    public function durationLabel(): string
    {
        $s = $this->seconds();

        return $s >= 3600 ? floor($s / 3600).'h '.floor(($s % 3600) / 60).'m' : floor($s / 60).'m '.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT).'s';
    }
}
