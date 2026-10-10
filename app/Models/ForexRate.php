<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.4 the forex desk's buy rate for one currency on one business date. */
class ForexRate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'reference_rate' => 'decimal:4', 'buy_rate' => 'decimal:4', 'carried' => 'boolean'];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function setter()
    {
        return $this->belongsTo(User::class, 'set_by');
    }

    /** Spread below the reference rate, in %. */
    public function margin(): float
    {
        return (float) $this->reference_rate > 0 ? round((1 - (float) $this->buy_rate / (float) $this->reference_rate) * 100, 2) : 0.0;
    }
}
