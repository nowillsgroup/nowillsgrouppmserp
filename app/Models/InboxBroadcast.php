<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.3 A one-off notice sent to a group of guests (in-house, today's arrivals / departures, one floor). */
class InboxBroadcast extends Model
{
    use BelongsToHotel;

    public const AUDIENCES = ['inhouse' => 'All in-house guests', 'arrivals' => "Today's arrivals", 'departures' => "Today's departures", 'floor' => 'In-house guests on one floor'];

    public const CHANNELS = ['best' => 'Best available (WhatsApp, else SMS, else portal chat)', 'portal' => 'Guest portal chat only', 'whatsapp' => 'WhatsApp only', 'sms' => 'SMS only'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['skipped_reasons' => 'array'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function floor()
    {
        return $this->belongsTo(Floor::class);
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class, 'broadcast_id');
    }
}
