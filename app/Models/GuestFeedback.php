<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHotel;
use Illuminate\Database\Eloquent\Model;

/** A guest's feedback – from a survey invitation (v3.1), the guest portal or the public form. */
class GuestFeedback extends Model
{
    use BelongsToHotel;

    protected $table = 'guest_feedback';

    protected $guarded = ['id', 'hotel_id'];

    public const SOURCES = ['survey' => 'Survey', 'portal' => 'Guest portal', 'web' => 'Public form', 'tv' => 'Room TV', 'kiosk' => 'Lobby kiosk'];

    protected function casts(): array
    {
        return ['aspects' => 'array', 'in_house' => 'boolean', 'consent_publish' => 'boolean', 'reviewed_at' => 'datetime', 'review_click_at' => 'datetime',
            'rating_overall' => 'integer', 'nps' => 'integer', 'score' => 'integer'];
    }

    public function reservation()
    {
        return $this->belongsTo(Reservation::class);
    }

    public function stay()
    {
        return $this->belongsTo(ReservationRoom::class, 'reservation_room_id');
    }

    public function guest()
    {
        return $this->belongsTo(Guest::class);
    }

    public function survey()
    {
        return $this->belongsTo(Survey::class);
    }

    public function invitation()
    {
        return $this->belongsTo(SurveyInvitation::class, 'invitation_id');
    }

    public function answers()
    {
        return $this->hasMany(FeedbackAnswer::class, 'feedback_id');
    }

    public function recoveryCase()
    {
        return $this->hasOne(FeedbackCase::class, 'feedback_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
