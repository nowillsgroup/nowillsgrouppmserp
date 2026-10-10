<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.5 – shift log book / hand-over entry. */
class LogEntry extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const SHIFTS = ['morning' => 'Morning', 'evening' => 'Evening', 'night' => 'Night'];

    public const DEPARTMENTS = ['front_office' => 'Front Office', 'housekeeping' => 'Housekeeping', 'fnb' => 'Food & Beverage', 'engineering' => 'Engineering',
        'security' => 'Security', 'management' => 'Management', 'all' => 'All departments'];

    /** key => [label, badge tone] */
    public const CATEGORIES = ['info' => ['Information', 'slate'], 'handover' => ['Hand-over', 'blue'], 'guest' => ['Guest', 'purple'], 'complaint' => ['Complaint', 'red'],
        'incident' => ['Incident', 'red'], 'vip' => ['VIP', 'amber'], 'maintenance' => ['Maintenance', 'amber'], 'security' => ['Security', 'red'], 'cash' => ['Cash / float', 'green']];

    public const PRIORITIES = ['normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'follow_up_at' => 'datetime', 'pinned' => 'boolean', 'pinned_until' => 'date', 'resolved_at' => 'datetime'];
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function reads()
    {
        return $this->hasMany(LogEntryRead::class);
    }

    public function comments()
    {
        return $this->hasMany(LogEntryComment::class)->orderBy('id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function followUpDue(): bool
    {
        return $this->isOpen() && $this->follow_up_at && $this->follow_up_at->lte(now());
    }

    public function readBy(?int $userId): bool
    {
        return $userId && ($this->created_by === $userId || $this->reads->contains('user_id', $userId));
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category][0] ?? ucfirst($this->category);
    }

    public function categoryTone(): string
    {
        return self::CATEGORIES[$this->category][1] ?? 'slate';
    }
}
