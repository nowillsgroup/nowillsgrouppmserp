<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RateContractRate extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['season_from' => 'date', 'season_to' => 'date', 'rate' => 'decimal:2', 'double_rate' => 'decimal:2', 'extra_adult_rate' => 'decimal:2'];
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function covers(string $date): bool
    {
        return ! $this->season_from || ($this->season_from->toDateString() <= $date && $this->season_to->toDateString() >= $date);
    }
}
