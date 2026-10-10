<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v6.6 One parking charge: a night on the room folio, the exit fee or a lost-ticket fee. */
class ParkingCharge extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['night' => 'date', 'business_date' => 'date'];
    }

    public function session()
    {
        return $this->belongsTo(ParkingSession::class, 'parking_session_id');
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }
}
