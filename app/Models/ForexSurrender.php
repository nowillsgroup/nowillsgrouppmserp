<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.4 foreign currency handed over to the bank / authorised dealer / franchiser, with the cedis realised. */
class ForexSurrender extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'total_cost' => 'decimal:2', 'total_realised' => 'decimal:2', 'gain' => 'decimal:2'];
    }

    public function lines()
    {
        return $this->hasMany(ForexSurrenderLine::class);
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
