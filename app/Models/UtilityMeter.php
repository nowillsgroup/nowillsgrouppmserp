<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.9 – a utility meter (grid electricity, DG set, solar, water, LPG, diesel, piped gas); sub-meters hang off a parent. */
class UtilityMeter extends Model
{
    use BelongsToHotel;

    /** utility => [label, default unit, colour, icon] */
    public const UTILITIES = [
        'electricity' => ['Electricity – grid', 'kWh', '#2563eb', 'alert'],
        'dg' => ['Electricity – DG set', 'kWh', '#7c3aed', 'tool'],
        'solar' => ['Electricity – solar', 'kWh', '#f59e0b', 'star'],
        'water' => ['Water', 'm3', '#0891b2', 'globe'],
        'lpg' => ['LPG', 'kg', '#dc2626', 'coffee'],
        'diesel' => ['Diesel (HSD)', 'L', '#475569', 'car'],
        'png' => ['Piped natural gas', 'SCM', '#16a34a', 'coffee'],
    ];

    public const UNITS = ['kWh' => 'kWh', 'm3' => 'm³ (kL)', 'kg' => 'kg', 'L' => 'litres', 'SCM' => 'SCM'];

    public const AREAS = ['Whole hotel', 'Rooms', 'Kitchen', 'Laundry', 'Public areas', 'HVAC / chiller', 'Pool & spa', 'Banquets', 'Staff areas', 'Plant room'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['multiplier' => 'float', 'rate' => 'float', 'is_main' => 'boolean', 'is_active' => 'boolean', 'digits' => 'integer', 'alert_pct' => 'integer'];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function readings()
    {
        return $this->hasMany(MeterReading::class);
    }

    public function bills()
    {
        return $this->hasMany(UtilityBill::class);
    }

    public function lastReading()
    {
        return $this->hasOne(MeterReading::class)->ofMany(['reading_date' => 'max', 'id' => 'max']);
    }

    public function utilityLabel(): string
    {
        return self::UTILITIES[$this->utility][0] ?? $this->utility;
    }

    public function color(): string
    {
        return self::UTILITIES[$this->utility][2] ?? '#64748b';
    }

    public function unitLabel(): string
    {
        return self::UNITS[$this->unit] ?? $this->unit;
    }

    /** compact unit symbol for figures (kWh, m³, kg, L, SCM) */
    public function unitShort(): string
    {
        return ['m3' => 'm³'][$this->unit] ?? $this->unit;
    }

    public function isElectric(): bool
    {
        return in_array($this->utility, ['electricity', 'dg', 'solar'], true);
    }

    /** the register value at which the meter rolls back to zero */
    public function rolloverAt(): float
    {
        return (float) (10 ** max(3, min(9, $this->digits ?: 7)));
    }
}
