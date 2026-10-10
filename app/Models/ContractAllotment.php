<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ContractAllotment extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date_from' => 'date', 'date_to' => 'date'];
    }

    public function contract()
    {
        return $this->belongsTo(RateContract::class, 'rate_contract_id');
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }
}
