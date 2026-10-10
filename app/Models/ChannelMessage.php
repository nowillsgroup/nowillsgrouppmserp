<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** One inbound reservation message from a channel (new / modify / cancel) – the OTA inbox. */
class ChannelMessage extends Model
{
    use BelongsToHotel;

    public const ATTENTION = [
        'unmapped_room' => 'Room / rate not mapped', 'overbooked' => 'Overbooked – no inventory', 'modify_inhouse' => 'Modification for an in-house / departed stay',
        'cancel_inhouse' => 'Cancellation for an in-house / departed stay', 'cancelled_booking' => 'Modification for a cancelled booking',
    ];

    public const TONES = ['processed' => 'green', 'attention' => 'amber', 'failed' => 'red', 'dismissed' => 'slate', 'received' => 'blue'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'warnings' => 'array', 'resolved_at' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function statusBadge(): string
    {
        return '<span class="badge badge-'.(self::TONES[$this->status] ?? 'slate').'">'.e(ucfirst($this->status)).'</span>';
    }

    public function guestName(): string
    {
        $g = $this->payload['guest'] ?? [];

        return trim(($g['first_name'] ?? '').' '.($g['last_name'] ?? '')) ?: '—';
    }

    public function connection()
    {
        return $this->belongsTo(ChannelConnection::class, 'channel_connection_id');
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
