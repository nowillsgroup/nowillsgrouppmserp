<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class RoomBlock extends Model
{
    use BelongsToHotel;

    protected $table = 'room_blocks';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['arrival_date' => 'date', 'departure_date' => 'date', 'cutoff_date' => 'date', 'rate' => 'decimal:2', 'released_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(RoomBlockLine::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function source()
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }

    /** Holds inventory only while tentative/definite and before release. */
    public function isHolding(): bool
    {
        return in_array($this->status, ['tentative', 'definite']);
    }
}
