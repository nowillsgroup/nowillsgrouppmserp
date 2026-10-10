<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class ReservationAmendment extends Model
{
    use BelongsToHotel;

    protected $table = 'reservation_amendments';

    protected $guarded = ['id', 'hotel_id'];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'before_state' => 'array',
            'after_state' => 'array',
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function eventLabel(): string
    {
        return match ($this->event) {
            'created' => 'Created',
            'amended' => 'Amended',
            'confirmed' => 'Confirmed',
            'cancelled' => 'Cancelled',
            'no_show' => 'Marked no-show',
            'assigned' => 'Rooms assigned',
            default => ucwords(str_replace('_', ' ', $this->event)),
        };
    }
}
