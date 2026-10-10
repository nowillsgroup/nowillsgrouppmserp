<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class BanquetService extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'float', 'tax_rate' => 'float'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
