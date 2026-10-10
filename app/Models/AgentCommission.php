<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class AgentCommission extends Model
{
    use BelongsToHotel;

    protected $table = 'agent_commissions';

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'paid_at' => 'datetime', 'room_revenue' => 'decimal:2', 'pct' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }
}
