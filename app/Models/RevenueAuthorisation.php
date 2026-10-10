<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.3 A complimentary room, house-use room, below-expected rate or folio discount approved in advance (four-eyes). */
class RevenueAuthorisation extends Model
{
    use BelongsToHotel;

    public const KINDS = ['comp' => 'Complimentary room', 'house_use' => 'House use', 'rate' => 'Rate below expected', 'discount' => 'Folio discount / allowance'];

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'cancelled' => 'Cancelled'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date', 'amount' => 'decimal:2', 'previous_rate' => 'decimal:2', 'decided_at' => 'datetime', 'applied_at' => 'datetime', 'restored_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function transaction()
    {
        return $this->belongsTo(FolioTransaction::class, 'folio_transaction_id');
    }

    public function nights(): int
    {
        return $this->date_from && $this->date_to ? (int) $this->date_from->diffInDays($this->date_to) + 1 : 0;
    }

    public function covers(string $date): bool
    {
        return $this->date_from && $this->date_to && $this->date_from->toDateString() <= $date && $this->date_to->toDateString() >= $date;
    }

    public function subject(): string
    {
        if ($this->stay) {
            return 'Room '.($this->stay->room?->room_no ?? '—').' · '.($this->stay->guest?->full_name ?? $this->stay->reservation?->reservation_no);
        }

        return $this->folio ? 'Folio '.$this->folio->folio_no : '—';
    }
}
