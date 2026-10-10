<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class AssetDepRun extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['period_end' => 'date', 'total' => 'decimal:2', 'reversed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(AssetDepLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reverser()
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function label(): string
    {
        return $this->period_end->format('F Y');
    }
}
