<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.5 One guest on an event's guest list, kept by the organiser in the portal (names, dietary needs, VIPs …). */
class OrganiserAttendee extends Model
{
    use BelongsToHotel;

    public const TAGS = ['delegate' => 'Delegate', 'vip' => 'VIP', 'speaker' => 'Speaker', 'companion' => 'Companion', 'crew' => 'Crew'];

    protected $guarded = ['id', 'hotel_id'];

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function adder()
    {
        return $this->belongsTo(OrganiserUser::class, 'added_by');
    }
}
