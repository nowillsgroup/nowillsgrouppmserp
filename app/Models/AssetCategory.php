<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class AssetCategory extends Model
{
    use BelongsToHotel;

    public const METHODS = ['slm' => 'Straight line', 'wdv' => 'Reducing balance (WDV)'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'wdv_rate' => 'decimal:2', 'residual_pct' => 'decimal:2'];
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function ruleLabel(): string
    {
        return $this->method === 'wdv'
            ? rtrim(rtrim(number_format((float) $this->wdv_rate, 2), '0'), '.').' % a year, reducing'
            : 'Straight line, '.self::years($this->life_months);
    }

    public static function years(int $months): string
    {
        return $months % 12 === 0 ? ($months / 12).' yr' : round($months / 12, 1).' yr';
    }
}
