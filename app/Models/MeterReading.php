<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.9 – one register reading of a utility meter on a business date, with the consumption since the previous one. */
class MeterReading extends Model
{
    use BelongsToHotel;

    public const ANOMALIES = ['high' => ['Unusually high', 'red'], 'low' => ['Unusually low', 'amber'], 'zero' => ['No consumption', 'amber']];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['reading_date' => 'date', 'reading' => 'float', 'consumption' => 'float', 'cost' => 'float', 'is_estimated' => 'boolean',
            'meter_reset' => 'boolean', 'rolled_over' => 'boolean', 'anomaly_ack_at' => 'datetime', 'days' => 'integer'];
    }

    public function meter()
    {
        return $this->belongsTo(UtilityMeter::class, 'utility_meter_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function perDay(): float
    {
        return $this->consumption / max(1, $this->days);
    }
}
