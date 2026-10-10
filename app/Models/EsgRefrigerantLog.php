<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 Refrigerant added to / recovered from a cooling system (F-gas log). Emissions = kg added × GWP. */
class EsgRefrigerantLog extends Model
{
    use BelongsToHotel;

    /** IPCC AR5 100-year global warming potentials (blends computed from their components) */
    public const GASES = ['R22' => ['R-22 (HCFC)', 1760], 'R410A' => ['R-410A', 1924], 'R32' => ['R-32', 677], 'R134a' => ['R-134a', 1300],
        'R404A' => ['R-404A', 3943], 'R407C' => ['R-407C', 1624], 'R290' => ['R-290 propane', 3], 'R600a' => ['R-600a isobutane', 3], 'R717' => ['R-717 ammonia', 0]];

    public const REASONS = ['leak_repair' => 'Leak found & repaired', 'top_up' => 'Top-up (no leak found)', 'install' => 'New installation / initial charge',
        'service' => 'Service (recover & recharge)', 'decommission' => 'Decommissioned – gas recovered'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'system_charge_kg' => 'decimal:2', 'kg_added' => 'decimal:3', 'kg_recovered' => 'decimal:3'];
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function ticket()
    {
        return $this->belongsTo(MaintenanceRequest::class, 'maintenance_request_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function gwp(string $gas): int
    {
        return self::GASES[$gas][1] ?? 0;
    }

    /** a first charge into a new system is not an emission; everything else added replaces gas that escaped */
    /** gas lost to the atmosphere (GHG Protocol refrigerant method): an initial charge emits nothing; a service loses what was put back beyond what
     *  was recovered; a decommissioned system loses whatever of its charge was not recovered; a top-up replaces gas that leaked out */
    public function emittedKg(): float
    {
        return match ($this->reason) {
            'install' => 0.0,
            'service' => max(0.0, (float) $this->kg_added - (float) $this->kg_recovered),
            'decommission' => $this->system_charge_kg !== null ? max(0.0, (float) $this->system_charge_kg - (float) $this->kg_recovered) : 0.0,
            default => (float) $this->kg_added,
        };
    }

    /** operating leakage for the leak rate (disposal losses are not a leak) */
    public function leakedKg(): float
    {
        return $this->reason === 'decommission' ? 0.0 : $this->emittedKg();
    }

    public function co2e(): float
    {
        return round($this->emittedKg() * self::gwp($this->gas), 1);
    }
}
