<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class CashierShift extends Model
{
    use BelongsToHotel;

    protected $table = 'cashier_shifts';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [ 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'business_date' => 'date', 'summary' => 'array', 'counted_denominations' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements()
    {
        return $this->hasMany(CashierMovement::class, 'cashier_shift_id');
    }
}
