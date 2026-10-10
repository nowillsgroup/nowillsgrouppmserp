<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class LoyaltyEntry extends Model
{
    use BelongsToHotel;

    protected $table = 'loyalty_ledger';

    protected $guarded = ['id', 'hotel_id'];

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
