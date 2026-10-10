<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.1 A message to one room's TV, a broadcast to a set of TVs, or a full-screen emergency alert on every screen. */
class TvMessage extends Model
{
    use BelongsToHotel;

    public const KINDS = ['room' => 'Room message', 'broadcast' => 'Broadcast', 'emergency' => 'Emergency alert'];

    public const TARGETS = ['room' => 'One room', 'floor' => 'A floor', 'all' => 'Every in-room TV', 'vip' => 'VIP rooms', 'group' => 'A group booking', 'signage' => 'Public screens'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'closed_at' => 'datetime', 'requires_ack' => 'boolean'];
    }

    public function reads()
    {
        return $this->hasMany(TvMessageRead::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    /** scheduled / live / expired / cancelled / cleared */
    public function state(): string
    {
        return match (true) {
            in_array($this->status, ['cancelled', 'cleared'], true) => $this->status,
            $this->starts_at->gt(now()) => 'scheduled',
            $this->ends_at && $this->ends_at->lte(now()) => 'expired',
            default => 'live',
        };
    }

    public function stateTone(): string
    {
        return ['live' => $this->kind === 'emergency' ? 'red' : 'green', 'scheduled' => 'blue', 'expired' => 'slate', 'cancelled' => 'slate', 'cleared' => 'teal'][$this->state()];
    }

    public function targetLabel(): string
    {
        return match ($this->target) {
            'room' => 'Room '.(Room::find($this->target_id)?->room_no ?? '?'),
            'floor' => Floor::find($this->target_id)?->name ?? 'Floor',
            'group' => 'Group '.(($r = Reservation::find($this->target_id)) ? ($r->group_name ?: $r->reservation_no) : '?'),
            default => self::TARGETS[$this->target] ?? $this->target,
        };
    }
}
