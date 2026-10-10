<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelGroup extends Model
{
    protected $table = 'hotel_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function hotels()
    {
        return $this->hasMany(Hotel::class);
    }
}
