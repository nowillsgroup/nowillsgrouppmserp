<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class StockTake extends Model
{
    use BelongsToHotel;

    protected $table = 'stock_takes';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['count_date' => 'date', 'posted_at' => 'datetime', 'variance_value' => 'decimal:2'];
    }

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function lines()
    {
        return $this->hasMany(StockTakeLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
