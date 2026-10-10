<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class BusinessSource extends Model
{
    use BelongsToHotel;

    protected $table = 'business_sources';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function reservations()
    {
        return $this->hasMany(Reservation::class);
    }
}
