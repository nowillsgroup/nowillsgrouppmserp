<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.9 A settlement with an activity vendor: what the hotel collected for them less its commission, net of commission they owe. */
class ActivitySettlement extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'up_to' => 'date', 'payable' => 'decimal:2', 'receivable' => 'decimal:2', 'net' => 'decimal:2'];
    }

    public function vendor()
    {
        return $this->belongsTo(ActivityVendor::class, 'activity_vendor_id');
    }

    public function bookings()
    {
        return $this->hasMany(ActivityBooking::class);
    }

    public function account()
    {
        return $this->belongsTo(AccountHead::class, 'account_head_id');
    }

    public function journal()
    {
        return $this->belongsTo(GlJournal::class, 'gl_journal_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function direction(): string
    {
        return (float) $this->net > 0 ? 'Paid to vendor' : ((float) $this->net < 0 ? 'Received from vendor' : 'Contra (no cash)');
    }
}
