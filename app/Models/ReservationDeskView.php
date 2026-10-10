<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReservationDeskView extends Model
{
    use BelongsToHotel;

    protected $table = 'reservation_desk_views';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
