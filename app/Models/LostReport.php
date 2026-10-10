<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.5 – a guest's report of an item lost at the hotel (inquiry), matched against found items. */
class LostReport extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['lost_date' => 'date'];
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function foundItem()
    {
        return $this->belongsTo(LostFoundItem::class, 'lost_found_item_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
