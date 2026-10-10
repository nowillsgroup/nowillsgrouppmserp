<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.0 Refrigerators, freezers and hot-holding units whose temperatures are logged every shift. */
class FsEquipment extends Model
{
    use BelongsToHotel;

    protected $table = 'fs_equipment';

    public const TYPES = ['fridge' => 'Refrigerator / walk-in chiller', 'freezer' => 'Freezer', 'hot_hold' => 'Hot holding (bain-marie / hot cupboard)', 'chiller' => 'Blast chiller', 'display' => 'Display chiller'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_c' => 'decimal:1', 'max_c' => 'decimal:1'];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function logs()
    {
        return $this->hasMany(FsTempLog::class, 'fs_equipment_id');
    }

    public function limitText(): string
    {
        return \App\Services\FoodSafetyService::limitText($this->min_c, $this->max_c);
    }
}
