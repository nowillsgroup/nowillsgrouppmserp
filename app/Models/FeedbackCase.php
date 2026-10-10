<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** v3.1 a service-recovery case, opened automatically from poor feedback / reviews or by staff. */
class FeedbackCase extends Model
{
    use BelongsToHotel;

    protected $guarded = ['id', 'hotel_id'];

    public const STATUSES = ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    public const SEVERITIES = ['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    public const ACTIONS = ['apology' => 'Apology / explanation', 'fixed' => 'Problem fixed', 'room_move' => 'Room change / upgrade', 'complimentary' => 'Complimentary service', 'goodwill_points' => 'Goodwill loyalty points', 'rate_adjustment' => 'Rate adjustment / credit note', 'none' => 'No action possible'];

    public const OUTCOMES = ['recovered' => 'Guest satisfied (recovered)', 'not_recovered' => 'Guest still unhappy', 'no_reply' => 'No reply from guest'];

    protected function casts(): array
    {
        return ['due_at' => 'datetime', 'first_response_at' => 'datetime', 'escalated_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime', 'in_house' => 'boolean'];
    }

    public function feedback()
    {
        return $this->belongsTo(GuestFeedback::class, 'feedback_id');
    }

    public function review()
    {
        return $this->belongsTo(OnlineReview::class, 'review_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function opener()
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function events()
    {
        return $this->hasMany(FeedbackCaseEvent::class, 'case_id')->orderBy('id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'in_progress']);
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->due_at->isPast();
    }

    public function scopeActive($q)
    {
        return $q->whereIn('status', ['open', 'in_progress']);
    }

    public function scopeOverdue($q)
    {
        return $q->whereIn('status', ['open', 'in_progress'])->where('due_at', '<', now());
    }
}
