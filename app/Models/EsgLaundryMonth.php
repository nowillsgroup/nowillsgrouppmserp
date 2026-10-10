<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 Outsourced laundry processed in a month (kg), for the HCMI Scope 3 laundry allowance. */
class EsgLaundryMonth extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'kg' => 'decimal:1', 'kwh' => 'decimal:1'];
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
