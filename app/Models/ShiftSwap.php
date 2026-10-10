<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.9 A shift swap / hand-over: requester → colleague accepts → manager approves → roster updated. */
class ShiftSwap extends Model
{
    use BelongsToHotel;

    public const OPEN = ['pending_peer', 'pending_manager'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['peer_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function requester()
    {
        return $this->belongsTo(Employee::class, 'requester_id');
    }

    public function colleague()
    {
        return $this->belongsTo(Employee::class, 'colleague_id');
    }

    public function shift()
    {
        return $this->belongsTo(RosterShift::class, 'roster_shift_id');
    }

    public function colleagueShift()
    {
        return $this->belongsTo(RosterShift::class, 'colleague_shift_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
