<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A movie rented on a room TV and charged to the stay's folio. */
class TvPurchase extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'tax' => 'decimal:2', 'total' => 'decimal:2', 'starts_at' => 'datetime', 'expires_at' => 'datetime', 'refunded_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function movie()
    {
        return $this->belongsTo(TvMovie::class, 'tv_movie_id');
    }

    public function device()
    {
        return $this->belongsTo(TvDevice::class, 'tv_device_id');
    }

    public function transaction()
    {
        return $this->belongsTo(FolioTransaction::class, 'folio_transaction_id');
    }

    public function refunder()
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function state(): string
    {
        return $this->status === 'refunded' ? 'refunded' : ($this->expires_at->gt(now()) ? 'watching' : 'ended');
    }

    public function stateTone(): string
    {
        return ['refunded' => 'red', 'watching' => 'green', 'ended' => 'slate'][$this->state()];
    }
}
