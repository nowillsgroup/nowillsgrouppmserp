<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.7 – carrier tariff matched on the dialled number's longest prefix. */
class CallTariff extends Model
{
    use BelongsToHotel;

    public const CLASSES = ['local' => 'Local', 'std' => 'National (STD)', 'mobile' => 'Mobile', 'isd' => 'International (ISD)', 'premium' => 'Premium rate', 'free' => 'Toll-free'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['cost_per_pulse' => 'float', 'markup_pct' => 'float', 'min_charge' => 'float', 'is_active' => 'boolean', 'pulse_secs' => 'integer', 'free_secs' => 'integer'];
    }

    /** guest price of one pulse */
    public function pricePerPulse(): float
    {
        return round($this->cost_per_pulse * (1 + $this->markup_pct / 100), 2);
    }

    /** guest price per minute – what the tariff card shows */
    public function pricePerMinute(): float
    {
        return $this->pulse_secs > 0 ? round($this->pricePerPulse() * 60 / $this->pulse_secs, 2) : 0.0;
    }
}
