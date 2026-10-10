<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.3 A two-way conversation with a guest on one channel (portal chat, WhatsApp, SMS, e-mail). */
class Conversation extends Model
{
    use BelongsToHotel;

    public const CHANNELS = ['portal' => 'Guest portal chat', 'whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'E-mail'];

    public const CHANNEL_ICONS = ['portal' => 'message', 'whatsapp' => 'phone', 'sms' => 'phone', 'email' => 'mail'];

    public const STATUSES = ['open' => ['Open', 'blue'], 'pending' => ['Waiting for guest', 'amber'], 'snoozed' => ['Snoozed', 'purple'], 'closed' => ['Closed', 'slate']];

    public const DEPARTMENTS = ['front_desk' => 'Front desk', 'reservations' => 'Reservations', 'housekeeping' => 'Housekeeping', 'engineering' => 'Engineering',
        'concierge' => 'Concierge', 'fnb' => 'Food & beverage', 'guest_relations' => 'Guest relations'];

    public const ACTIVE = ['open', 'pending', 'snoozed'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['awaiting_since' => 'datetime', 'last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'opened_at' => 'datetime', 'snoozed_until' => 'datetime',
            'after_hours_sent_at' => 'datetime', 'closed_at' => 'datetime', 'opted_out_at' => 'datetime', 'csat_asked' => 'boolean'];
    }

    public function messages()
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function lastMessage()
    {
        return $this->hasOne(ConversationMessage::class)->whereIn('direction', ['in', 'out'])->latestOfMany();
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function displayName(): string
    {
        return $this->guest?->full_name ?: ($this->contact_name ?: $this->handleLabel());
    }

    public function handleLabel(): string
    {
        return $this->channel === 'portal' ? 'Guest portal' : $this->handle;
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? ucfirst($this->channel);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? ucfirst($this->status);
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }

    public function departmentLabel(): string
    {
        return self::DEPARTMENTS[$this->department] ?? ucfirst(str_replace('_', ' ', $this->department));
    }

    /** minutes the guest has been waiting for an answer (null when nothing is outstanding) */
    public function waitingMinutes(): ?int
    {
        return $this->awaiting_since && $this->status !== 'closed' ? (int) floor($this->awaiting_since->diffInSeconds(now()) / 60) : null;
    }

    public function isOverdue(int $slaMinutes): bool
    {
        $w = $this->waitingMinutes();

        return $w !== null && $w >= $slaMinutes && $this->status !== 'snoozed';
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    public function roomNo(): ?string
    {
        return $this->stay?->room?->room_no;
    }
}
