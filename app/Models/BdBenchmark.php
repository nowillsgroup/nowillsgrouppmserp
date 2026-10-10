<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.3 Monthly competitive-set benchmark (STR-style occupancy and ADR of the comp set). */
class BdBenchmark extends Model
{
    use BelongsToHotel;

    public const SOURCES = ['str' => 'STR / CoStar report', 'manual' => 'Comp-set exchange', 'estimate' => 'Estimate'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['month' => 'date', 'comp_occupancy' => 'decimal:2', 'comp_adr' => 'decimal:2', 'own_occupancy' => 'decimal:2', 'own_adr' => 'decimal:2'];
    }

    /** the report states our own figures ("My Property") */
    public function hasOwn(): bool
    {
        return $this->own_occupancy !== null && $this->own_adr !== null;
    }

    public function compRevpar(): float
    {
        return round((float) $this->comp_occupancy * (float) $this->comp_adr / 100, 2);
    }
}
