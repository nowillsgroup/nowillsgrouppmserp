<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.9 – a utility supplier's bill for a meter and period, reconciled with metered consumption. */
class UtilityBill extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'period_from' => 'date', 'period_to' => 'date', 'due_date' => 'date', 'units_billed' => 'float', 'metered_units' => 'float',
            'energy_charges' => 'float', 'fixed_charges' => 'float', 'taxes' => 'float', 'total' => 'float'];
    }

    public function meter()
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function supplierInvoice()
    {
        return $this->belongsTo(SupplierInvoice::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function varianceUnits(): float
    {
        return round($this->units_billed - $this->metered_units, 3);
    }

    public function variancePct(): ?float
    {
        return $this->metered_units > 0 ? round(($this->units_billed - $this->metered_units) / $this->metered_units * 100, 1) : null;
    }

    public function effectiveRate(): float
    {
        return $this->units_billed > 0 ? round($this->total / $this->units_billed, 4) : 0.0;
    }
}
