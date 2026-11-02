<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

class FrontDeskShiftSession extends Model
{
    use BelongsToHotel;

    public const STATUSES = [
        'open' => 'In progress',
        'handover_pending' => 'Awaiting hand-over acknowledgement',
        'closed' => 'Closed & acknowledged',
    ];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'started_at' => 'datetime', 'closed_at' => 'datetime', 'received_at' => 'datetime', 'handover_snapshot' => 'array'];
    }

    public function starter()
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function incomingUser()
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function logEntries()
    {
        return $this->hasMany(LogEntry::class, 'shift_session_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            'open' => 'green',
            'handover_pending' => 'amber',
            'closed' => 'slate',
            default => 'slate',
        };
    }
}
