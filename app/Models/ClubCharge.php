<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 A line on a member's account: subscription (deferred, recognised over its period), joining fee, guest pass, credit note. */
class ClubCharge extends Model
{
    use BelongsToHotel;

    public const TYPES = ['subscription' => 'Subscription', 'joining' => 'Joining fee', 'guest_pass' => 'Guest pass', 'credit' => 'Credit note', 'other' => 'Other charge'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'due_date' => 'date', 'cancelled_at' => 'datetime',
            'amount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'paid' => 'decimal:2'];
    }

    public function membership()
    {
        return $this->belongsTo(ClubMembership::class, 'club_membership_id');
    }

    public function creditOf()
    {
        return $this->belongsTo(ClubCharge::class, 'credit_of_id');
    }

    public function credits()
    {
        return $this->hasMany(ClubCharge::class, 'credit_of_id')->whereNull('cancelled_at');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outstanding(): float
    {
        return $this->cancelled_at ? 0.0 : round((float) $this->total - (float) $this->paid, 2);
    }

    public function state(): string
    {
        if ($this->cancelled_at) {
            return 'cancelled';
        }
        if ($this->type === 'credit') {
            return 'credit';
        }
        $o = $this->outstanding();

        return $o <= 0.004 ? 'paid' : ((float) $this->paid > 0 ? 'part' : 'open');
    }
}
