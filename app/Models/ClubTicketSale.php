<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.38 POS-style ticket outlet sale — header with split-tender payments. */
class ClubTicketSale extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'cancelled_at' => 'datetime', 'subtotal' => 'decimal:2', 'tax' => 'decimal:2',
            'total' => 'decimal:2', 'paid' => 'decimal:2', 'change_due' => 'decimal:2'];
    }

    public function items()
    {
        return $this->hasMany(ClubTicketSaleItem::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(ClubTicketPayment::class, 'sale_id');
    }

    public function passes()
    {
        return $this->hasMany(ClubDayPass::class, 'sale_id');
    }

    public function host()
    {
        return $this->belongsTo(ClubMember::class, 'host_member_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }
}
