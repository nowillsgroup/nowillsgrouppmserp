<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.6 – rooms statistics for one fiscal month of a budget (available rooms, occupancy %, ADR). */
class BudgetStat extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['month' => 'integer', 'rooms_available' => 'integer', 'occupancy' => 'float', 'adr' => 'float'];
    }

    public function roomsSold(): int
    {
        return (int) round($this->rooms_available * $this->occupancy / 100);
    }

    public function roomRevenue(): float
    {
        return round($this->roomsSold() * $this->adr, 2);
    }

    public function revpar(): float
    {
        return $this->rooms_available ? round($this->roomRevenue() / $this->rooms_available, 2) : 0.0;
    }
}
