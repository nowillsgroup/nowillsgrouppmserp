<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class BanquetEventItem extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['qty' => 'float', 'unit_price' => 'float', 'tax_rate' => 'float'];
    }

    public function function()
    {
        return $this->belongsTo(BanquetFunction::class, 'banquet_function_id');
    }

    public function service()
    {
        return $this->belongsTo(BanquetService::class, 'banquet_service_id');
    }

    public function amount(): float
    {
        return round($this->qty * $this->unit_price, 2);
    }
}
