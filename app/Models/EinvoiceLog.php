<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class EinvoiceLog extends Model
{
    use BelongsToHotel;

    public $timestamps = false;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean', 'created_at' => 'datetime'];
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    /** v4.4: restaurant bill stamped by E-VAT */
    public function posOrder()
    {
        return $this->belongsTo(PosOrder::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
