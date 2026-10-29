<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One direct-payment tender row for a classic Health Club day-pass sale. */
class ClubDayPassPayment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function dayPass()
    {
        return $this->belongsTo(ClubDayPass::class, 'club_day_pass_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }
}
