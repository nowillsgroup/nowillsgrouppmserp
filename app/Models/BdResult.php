<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 Actual revenue and cost of a live initiative for one month. */
class BdResult extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'revenue' => 'decimal:2', 'cost' => 'decimal:2'];
    }

    public function initiative()
    {
        return $this->belongsTo(BdInitiative::class, 'initiative_id');
    }
}
