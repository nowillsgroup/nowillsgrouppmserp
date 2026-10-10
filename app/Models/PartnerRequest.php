<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.4 Something a partner asks the hotel for: approve a booking held for credit / value, change a booking, quote a group. */
class PartnerRequest extends Model
{
    use BelongsToHotel;

    public const TYPES = ['approval' => 'Booking approval', 'amendment' => 'Change request', 'group' => 'Group quote'];

    public const STATUSES = ['pending' => 'Pending', 'approved' => 'Approved', 'declined' => 'Declined', 'done' => 'Done', 'withdrawn' => 'Withdrawn'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function partnerUser()
    {
        return $this->belongsTo(PartnerUser::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function badge(): string
    {
        return ['pending' => 'badge-amber', 'approved' => 'badge-green', 'done' => 'badge-green', 'declined' => 'badge-red', 'withdrawn' => 'badge-slate'][$this->status] ?? 'badge-slate';
    }

    /** Hours the partner waited for an answer (open requests: so far). */
    public function responseHours(): float
    {
        return round($this->created_at->diffInMinutes($this->decided_at ?? now()) / 60, 1);
    }
}
