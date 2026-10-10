<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v7.5 A change / cancellation / new-event quote request an organiser sent from the portal for the hotel to answer. */
class OrganiserRequest extends Model
{
    use BelongsToHotel;

    public const TYPES = ['change' => 'Change request', 'cancel' => 'Cancellation request', 'quote' => 'New-event quote'];

    public const STATUSES = ['pending' => 'Waiting for the hotel', 'approved' => 'Approved', 'declined' => 'Declined', 'done' => 'Done', 'withdrawn' => 'Withdrawn'];

    public const TONES = ['pending' => 'amber', 'approved' => 'green', 'declined' => 'red', 'done' => 'blue', 'withdrawn' => 'slate'];

    protected $guarded = ['id', 'hotel_id'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'decided_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function organiserUser()
    {
        return $this->belongsTo(OrganiserUser::class);
    }

    public function event()
    {
        return $this->belongsTo(BanquetEvent::class, 'banquet_event_id');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function statusBadge(): string
    {
        return '<span class="badge badge-'.(self::TONES[$this->status] ?? 'slate').'">'.e(self::STATUSES[$this->status] ?? ucfirst((string) $this->status)).'</span>';
    }

    /** Hours the organiser waited for an answer (open requests: so far). */
    public function responseHours(): float
    {
        return round($this->created_at->diffInMinutes($this->decided_at ?? now()) / 60, 1);
    }
}
