<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.5 – wake-up call. */
class WakeupCall extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['scheduled' => ['Scheduled', 'blue'], 'done' => ['Answered', 'green'], 'no_answer' => ['No answer', 'red'], 'missed' => ['Missed – not called', 'red'], 'cancelled' => ['Cancelled', 'slate']];

    public const SOURCES = ['desk' => 'Front desk', 'phone' => 'Guest phoned', 'portal' => 'Guest portal', 'tv' => 'Room TV'];

    protected function casts(): array
    {
        return ['call_at' => 'datetime', 'daily' => 'boolean', 'last_attempt_at' => 'datetime', 'completed_at' => 'datetime', 'escalated_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function attemptsLog()
    {
        return $this->hasMany(WakeupAttempt::class)->orderBy('id');
    }

    public function guestRequest()
    {
        return $this->belongsTo(GuestRequest::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isScheduled(): bool
    {
        return $this->status === 'scheduled';
    }

    /** scheduled and the call time has passed by more than the grace minutes */
    public function isLate(int $graceMinutes = 5): bool
    {
        return $this->isScheduled() && $this->call_at->copy()->addMinutes($graceMinutes)->lt(now());
    }

    /** scheduled and the call time is now (or within the lead window) */
    public function isDue(int $leadMinutes = 15): bool
    {
        return $this->isScheduled() && $this->call_at->lte(now()->addMinutes($leadMinutes));
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status][0] ?? $this->status;
    }

    public function statusTone(): string
    {
        return self::STATUSES[$this->status][1] ?? 'slate';
    }
}
