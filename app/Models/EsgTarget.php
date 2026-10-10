<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.0 A sustainability target: metric, baseline year and value, target year and value. */
class EsgTarget extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['baseline_value' => 'float', 'target_value' => 'float', 'is_active' => 'boolean'];
    }
}
