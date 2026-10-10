<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class SpaRoom extends Model
{
    use BelongsToHotel;

    protected $table = 'spa_rooms';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
