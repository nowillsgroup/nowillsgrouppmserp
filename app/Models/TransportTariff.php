<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.3 a line on the transport tariff card (one-way fare per route kind and vehicle type). */
class TransportTariff extends Model
{
    use BelongsToHotel;

    public const KINDS = ['airport' => 'Airport', 'railway' => 'Railway / bus station', 'local' => 'Local / city', 'outstation' => 'Outstation', 'tour' => 'Sightseeing tour'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['flat_rate' => 'decimal:2', 'per_km' => 'decimal:2', 'wait_per_hour' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function label(): string
    {
        return $this->name.' – '.money($this->flat_rate);
    }
}
