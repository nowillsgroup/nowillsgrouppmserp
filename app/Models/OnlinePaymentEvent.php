<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class OnlinePaymentEvent extends Model
{
    use BelongsToHotel;

    public const UPDATED_AT = null;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'signature_ok' => 'boolean', 'created_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(OnlinePayment::class, 'online_payment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
