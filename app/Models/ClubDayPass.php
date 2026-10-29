<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v5.1 Day pass for a walk-in visitor, or a guest pass bought by a member for their guest. */
class ClubDayPass extends Model
{
    use BelongsToHotel;

    public const KINDS = ['day' => 'Day pass', 'guest' => "Member's guest"];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['date' => 'date', 'cancelled_at' => 'datetime', 'amount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function host()
    {
        return $this->belongsTo(ClubMember::class, 'host_member_id');
    }

    public function paymentMode()
    {
        return $this->belongsTo(PaymentMode::class);
    }

    public function payments()
    {
        return $this->hasMany(ClubDayPassPayment::class, 'club_day_pass_id');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** v7.38 ticket outlet sale that issued this pass (null for classic desk day passes). */
    public function sale()
    {
        return $this->belongsTo(ClubTicketSale::class, 'sale_id');
    }

    public function ticketType()
    {
        return $this->belongsTo(ClubTicketType::class, 'ticket_type_id');
    }
}
