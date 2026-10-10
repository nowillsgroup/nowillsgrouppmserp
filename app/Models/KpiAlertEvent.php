<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.4 A KPI alert that fired for a business date, until someone acknowledges it. */
class KpiAlertEvent extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'value' => 'decimal:2', 'threshold' => 'decimal:2', 'acknowledged_at' => 'datetime'];
    }

    public function alert()
    {
        return $this->belongsTo(KpiAlert::class, 'kpi_alert_id');
    }

    public function acknowledger()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
