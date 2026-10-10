<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.4 a purchase of foreign currency from a guest – paid out in cedis or credited to the guest's bill. */
class ForexExchange extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const PAYOUTS = ['cash' => 'Cedis in cash', 'folio' => 'Credit to the room bill'];

    public const INSTRUMENTS = ['notes' => 'Currency notes', 'tc' => "Travellers' cheques"];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'fc_amount' => 'decimal:2', 'rate' => 'decimal:4', 'inr_amount' => 'decimal:2', 'fee' => 'decimal:2', 'gst_value' => 'decimal:2',
            'gst' => 'decimal:2', 'net_amount' => 'decimal:2', 'usd_equiv' => 'decimal:2', 'certificate' => 'boolean', 'voided_at' => 'datetime'];
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function folio()
    {
        return $this->belongsTo(Folio::class);
    }

    public function folioTransaction()
    {
        return $this->belongsTo(FolioTransaction::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider()
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function isVoid(): bool
    {
        return (bool) $this->voided_at;
    }

    public function fc(): string
    {
        return $this->currency?->code.' '.number_format((float) $this->fc_amount, 2);
    }
}
