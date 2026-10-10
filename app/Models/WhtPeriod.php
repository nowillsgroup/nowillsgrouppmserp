<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v4.4 A month whose GRA withholding tax return has been filed – withheld supplier payments dated in it are locked. */
class WhtPeriod extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['filed_on' => 'date', 'summary' => 'array'];
    }

    public function filer()
    {
        return $this->belongsTo(User::class, 'filed_by');
    }
}
