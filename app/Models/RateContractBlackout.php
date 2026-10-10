<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RateContractBlackout extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date'];
    }

    public function covers(string $date): bool
    {
        return $this->date_from->toDateString() <= $date && $this->date_to->toDateString() >= $date;
    }
}
