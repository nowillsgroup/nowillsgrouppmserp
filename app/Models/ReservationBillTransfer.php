<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** Historical source of a transaction moved to a reservation bill. */
class ReservationBillTransfer extends Model
{
    use BelongsToHotel;

    protected $table = 'reservation_bill_transfers';

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    public function sourceFolio()
    {
        return $this->belongsTo(Folio::class, 'source_folio_id');
    }

    public function targetFolio()
    {
        return $this->belongsTo(Folio::class, 'target_folio_id');
    }
}
